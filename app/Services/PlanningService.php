<?php

namespace App\Services;

use App\Models\AipDocument;
use App\Models\AuditEvent;
use App\Models\Budget;
use App\Models\Office;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanningService
{
    public static function cents(string|int|float $value): int
    {
        return (int) bcmul((string) $value, '100', 0);
    }

    public static function audit(
        User $user,
        string $type,
        int $id,
        string $action,
        ?int $office = null,
        ?string $comment = null,
        array $metadata = []
    ): void {
        AuditEvent::create([
            'user_id' => $user->id,
            'office_id' => $office,
            'subject_type' => $type,
            'subject_id' => $id,
            'action' => $action,
            'comment' => $comment,
            'metadata' => $metadata,
        ]);
    }

    public static function fail(string $message): never
    {
        throw ValidationException::withMessages([
            'workflow' => $message,
        ]);
    }

    public function save(
        User $user,
        array $data,
        ?Plan $existing = null
    ): Plan {
        return DB::transaction(function () use ($user, $data, $existing) {
            PlanningAccess::roles($user, ['admin', 'office']);
            abort_unless(in_array($existing?->type ?? $data['type'], ['aip', 'ppmp'], true), 422);
            Office::whereKey($existing?->office_id ?? $data['office_id'])->lockForUpdate()->firstOrFail();
            $officeId = (int) ($existing?->office_id ?? $data['office_id']);
            $year = (int) ($existing?->fiscal_year ?? $data['fiscal_year']);
            AipDocument::ensureWritable($officeId, $year, ($existing?->type ?? $data['type']) === 'ppmp');
            Budget::where('office_id', $officeId)->where('fiscal_year', $year)->lockForUpdate()->first();
            PpaHierarchy::prepare($user, $data, $existing);

            if ($existing) {
                $existing = Plan::whereKey($existing->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                PlanningAccess::edit($user, $existing);

                if ($existing->version !== (int) $data['version']) {
                    self::fail(
                        'This plan changed in another session. Reload before saving.'
                    );
                }

                $data['office_id'] = $existing->office_id;
                $data['fiscal_year'] = $existing->fiscal_year;
                $data['type'] = $existing->type;
            }

            if ($user->role === 'office') {
                abort_unless(
                    $user->office_id
                        && (int) $data['office_id'] === $user->office_id,
                    403
                );
            }

            $amounts = [
                'ps_cents' => 0,
                'mooe_cents' => 0,
                'fe_cents' => 0,
                'co_cents' => 0,
            ];

            $lines = [];

            if ($data['type'] === 'ppmp') {
                $data['parent_id'] = $existing?->parent_id ?? ($data['parent_id'] ?? null);
                $parent = Plan::whereKey($data['parent_id'])->lockForUpdate()->first();
                if (! $parent || ! $parent->permitsProcurementDrafts()
                    || $parent->office_id !== (int) $data['office_id']
                    || $parent->fiscal_year !== (int) $data['fiscal_year']) {
                    self::fail('Choose a draft, returned or approved PPA for this office and year.');
                }
                $duplicate = Plan::where('type', 'ppmp')->where('office_id', $parent->office_id)
                    ->where('fiscal_year', $parent->fiscal_year)->where('reference', $data['reference'])
                    ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages(['reference' => 'This PPMP reference is already in use.']);
                }
                $clearing = $existing !== null && ($data['items'] ?? null) === [];
                $lines = $clearing ? [] : ProcurementSchedule::lines($data['items'] ?? [], $existing);
                foreach ($lines as $line) {
                    $amounts[strtolower($line['expense_class']).'_cents'] += $line['total_cents'];
                }
                if (! $clearing && array_sum($amounts) <= 0) {
                    self::fail('The procurement total must be greater than zero.');
                }

                if (! $clearing) {
                    $budget = OfficeBudgetService::summary($officeId, $year, $existing?->id);
                    OfficeBudgetService::requireAvailable($budget, array_sum($amounts));
                }
            } else {
                $amounts['ps_cents'] = isset($data['ps']) ? self::cents($data['ps']) : ($existing?->ps_cents ?? 0);
                $amounts['fe_cents'] = isset($data['fe']) ? self::cents($data['fe']) : ($existing?->fe_cents ?? 0);
                if ($amounts['ps_cents'] < 0 || $amounts['fe_cents'] < 0) {
                    self::fail('PS and FE amounts must be zero or greater.');
                }
                $manual = $amounts['ps_cents'] + $amounts['fe_cents'];
                $previousManual = ($existing?->ps_cents ?? 0) + ($existing?->fe_cents ?? 0);
                if ($manual > $previousManual) {
                    OfficeBudgetService::requireAvailable(OfficeBudgetService::summary($officeId, $year), $manual, $previousManual);
                }
                if ($existing) {
                    $costs = OfficeBudgetService::procurementByPpa($officeId, $year, $existing->id)->get($existing->id);
                    $amounts['mooe_cents'] = (int) ($costs->mooe ?? 0);
                    $amounts['co_cents'] = (int) ($costs->co ?? 0);
                }
                $data['parent_id'] = null;
            }

            $fields = array_intersect_key(
                $data,
                array_flip([
                    'type',
                    'office_id',
                    'fiscal_year',
                    'reference',
                    'title',
                    'description',
                    'fund_source',
                    'expected_output',
                    'performance_indicator',
                    'physical_target',
                    'start_date',
                    'end_date',
                    'parent_id',
                    'parent_ppa_id',
                    'program_name',
                    'sector',
                    'sub_sector',
                    'division',
                ])
            );

            $fields = [
                ...$fields,
                ...$amounts,
                'total_cents' => array_sum($amounts),
            ];

            if ($data['type'] === 'aip') {
                $document = AipDocument::firstOrCreate([
                    'office_id' => $data['office_id'],
                    'fiscal_year' => $data['fiscal_year'],
                ], ['title' => 'Annual Investment Plan '.$data['fiscal_year']]);
                $fields['aip_document_id'] = $document->id;
            }

            if ($existing) {
                $existing->update([
                    ...$fields,
                    'version' => $existing->version + 1,
                ]);

                $plan = $existing;
                if ($plan->type === 'ppmp') {
                    $plan->items()->delete();
                }
            } else {
                $plan = Plan::create([
                    ...$fields,
                    'created_by' => $user->id,
                    'version' => 1,
                    'status' => 'draft',
                ]);
            }

            if ($plan->type === 'ppmp') {
                $plan->items()->createMany($lines);
                // Keep the existing PPA amount columns in sync for callers reading the saved plan.
                $costs = OfficeBudgetService::procurementByPpa($officeId, $year, $parent->id)->get($parent->id);
                $mooe = (int) ($costs->mooe ?? 0);
                $co = (int) ($costs->co ?? 0);
                $parent->update([
                    'mooe_cents' => $mooe,
                    'co_cents' => $co,
                    'total_cents' => $parent->ps_cents + $parent->fe_cents + $mooe + $co,
                ]);
            }

            self::audit(
                $user,
                'plan',
                $plan->id,
                $existing ? 'updated' : 'created',
                $plan->office_id,
                null,
                [
                    'version' => $plan->version,
                    'total_cents' => $plan->total_cents,
                ]
            );

            return $plan;
        }, 3);
    }

    public function transition(
        User $user,
        Plan $plan,
        string $action,
        int $version,
        ?string $comment
    ): void {
        DB::transaction(function () use (
            $user,
            $plan,
            $action,
            $version,
            $comment
        ) {
            // Use the same lock order as PPA saves and office budget changes.
            Office::whereKey($plan->office_id)->lockForUpdate()->firstOrFail();
            $budget = Budget::where('office_id', $plan->office_id)
                ->where('fiscal_year', $plan->fiscal_year)
                ->lockForUpdate()
                ->first();

            $plan = Plan::whereKey($plan->id)
                ->lockForUpdate()
                ->firstOrFail();

            PlanningAccess::view($user, $plan);

            if ($plan->type === 'program') {
                self::fail('Program headings are not submitted for financial approval.');
            }
            if (! in_array($action, ['submit', 'approve', 'return'], true)) {
                self::fail('Unknown workflow action.');
            }

            if ($plan->version !== $version) {
                self::fail(
                    'This plan changed. Reload the page before continuing.'
                );
            }

            if ($action === 'submit') {
                PlanningAccess::edit($user, $plan);

                if (! $budget) {
                    self::fail('The Budget Office must allocate an office budget for this year first.'
                    );
                }

                if ($plan->type === 'ppmp') {
                    if (! $plan->items()->exists()) {
                        self::fail('Add at least one item before submitting this PPMP.');
                    }
                    $parent = Plan::whereKey($plan->parent_id)->lockForUpdate()->first();
                    if (! $parent || $parent->type !== 'aip' || $parent->parent_ppa_id === null
                        || $parent->office_id !== $plan->office_id || $parent->fiscal_year !== $plan->fiscal_year) {
                        self::fail('The PPMP must belong to a PPA in the same office and fiscal year.');
                    }
                }
                // Submitting changes the status only. Its saved costs already count.
                OfficeBudgetService::requireAvailable(OfficeBudgetService::summary($plan->office_id, $plan->fiscal_year), 0);

                $plan->status = 'submitted';
                $plan->submitted_at = now();
            } else {
                PlanningAccess::roles(
                    $user,
                    [$plan->type === 'aip' ? 'budget' : 'mayor']
                );

                abort_if(
                    $plan->created_by === $user->id,
                    403,
                    'You cannot approve your own document.'
                );

                if ($plan->status !== 'submitted') {
                    self::fail('Only submitted documents can be reviewed.');
                }

                if ($action === 'return' && ! trim($comment ?? '')) {
                    self::fail('Explain what needs to be revised.');
                }

                $plan->status = $action === 'approve'
                    ? 'approved'
                    : 'returned';

                $plan->approved_at = $action === 'approve'
                    ? now()
                    : null;
            }

            $plan->version++;
            $plan->save();

            self::audit(
                $user,
                'plan',
                $plan->id,
                $action,
                $plan->office_id,
                $comment,
                [
                    'version' => $plan->version,
                    'status' => $plan->status,
                ]
            );
        }, 3);
    }
}
