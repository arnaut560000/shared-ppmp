<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Budget;
use App\Models\CatalogueItem;
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
                $parent = Plan::find($data['parent_id'] ?? null);

                if (
                    ! $parent
                    || $parent->type !== 'aip'
                    || $parent->status !== 'approved'
                    || $parent->office_id !== (int) $data['office_id']
                    || $parent->fiscal_year !== (int) $data['fiscal_year']
                ) {
                    self::fail(
                        'Select an approved AIP from the same office and fiscal year.'
                    );
                }

                foreach ($data['items'] ?? [] as $line) {
                    $item = CatalogueItem::where('active', true)
                        ->find($line['catalogue_item_id']);

                    if (! $item) {
                        self::fail(
                            'One of the selected catalogue items is no longer active.'
                        );
                    }

                    $quarters = array_map(
                        'intval',
                        $line['quarterly_quantities']
                    );

                    $quantity = array_sum($quarters);

                    if ($quantity < 1) {
                        self::fail(
                            'Each item needs a quantity in at least one quarter.'
                        );
                    }

                    $cost = $item->price_cents * $quantity;

                    $amounts[strtolower($item->expense_class).'_cents'] += $cost;

                    $lines[] = [
                        'catalogue_item_id' => $item->id,
                        ...$item->only([
                            'code',
                            'name',
                            'specification',
                            'unit',
                            'expense_class',
                            'price_cents',
                        ]),
                        'monthly_quantities' => null,
                        'quarterly_quantities' => $quarters,
                        'object_of_expenditure' => $item->category,
                        'quantity' => $quantity,
                        'total_cents' => $cost,
                    ];
                }

                if (! $lines) {
                    self::fail('Add at least one catalogue item.');
                }
            } else {
                foreach (array_keys($amounts) as $key) {
                    $amounts[$key] = self::cents(
                        $data[str_replace('_cents', '', $key)] ?? 0
                    );
                }

                $data['parent_id'] = null;
            }

            if (array_sum($amounts) <= 0) {
                self::fail('The plan total must be greater than zero.');
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
                    'start_date',
                    'end_date',
                    'parent_id',
                ])
            );

            $fields = [
                ...$fields,
                ...$amounts,
                'total_cents' => array_sum($amounts),
            ];

            if ($existing) {
                $existing->update([
                    ...$fields,
                    'version' => $existing->version + 1,
                ]);

                $plan = $existing;
                $plan->items()->delete();
            } else {
                $plan = Plan::create([
                    ...$fields,
                    'created_by' => $user->id,
                    'version' => 1,
                    'status' => 'draft',
                ]);
            }

            $plan->items()->createMany($lines);

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
        });
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
            // Lock the envelope first so concurrent submissions
            // cannot reserve the same balance.
            $budget = Budget::where('office_id', $plan->office_id)
                ->where('fiscal_year', $plan->fiscal_year)
                ->lockForUpdate()
                ->first();

            $plan = Plan::whereKey($plan->id)
                ->lockForUpdate()
                ->firstOrFail();

            PlanningAccess::view($user, $plan);

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

                if ($plan->type === 'aip') {
                    $reserved = Plan::where('type', 'aip')
                        ->where('office_id', $plan->office_id)
                        ->where('fiscal_year', $plan->fiscal_year)
                        ->whereIn('status', ['submitted', 'approved'])
                        ->where('id', '!=', $plan->id)
                        ->sum('total_cents');

                    if ($reserved + $plan->total_cents > $budget->ceiling_cents) {
                        self::fail(
                            'This AIP would exceed the office budget ceiling. Revise it or request an allocation adjustment.'
                        );
                    }
                } else {
                    $parent = Plan::whereKey($plan->parent_id)
                        ->lockForUpdate()
                        ->first();

                    if (! $parent || $parent->status !== 'approved') {
                        self::fail('The linked AIP must be approved.');
                    }

                    foreach (['mooe_cents', 'co_cents'] as $field) {
                        $reserved = Plan::where('parent_id', $parent->id)
                            ->whereIn('status', ['submitted', 'approved'])
                            ->where('id', '!=', $plan->id)
                            ->sum($field);

                        if ($reserved + $plan->$field > $parent->$field) {
                            self::fail(
                                'PPMP requirements exceed the linked AIP '
                                .strtoupper(str_replace('_cents', '', $field))
                                .' allocation.'
                            );
                        }
                    }
                }

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
