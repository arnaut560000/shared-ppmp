<?php

namespace App\Http\Controllers;

use App\Models\AipDocument;
use App\Models\AuditEvent;
use App\Models\Office;
use App\Models\Plan;
use App\Services\PlanningAccess;
use App\Services\PlanningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PpmpApprovalController extends Controller
{
    public function index(Request $request): Response
    {
        PlanningAccess::roles($request->user(), ['mayor']);

        $data = $request->validate([
            'year' => ['nullable', 'integer', 'between:2020,2100'],
        ]);

        $year = (int) ($data['year'] ?? now()->year);

        $sheets = Plan::where('type', 'ppmp')
            ->where('status', 'submitted')
            ->where('fiscal_year', $year)
            ->whereHas('parent', fn ($query) => $query
                ->where('type', 'aip')->whereNotNull('parent_ppa_id'))
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from('audit_events')
                    ->whereColumn('subject_id', 'plans.id')
                    ->whereColumn('audit_events.office_id', 'plans.office_id')
                    ->where('subject_type', 'plan')
                    ->where('action', 'complete_gso_review')
                    ->where('metadata->version', DB::raw('plans.version'));
            })
            ->with(['office:id,name', 'parent.aipDocument'])
            ->orderBy('submitted_at')->orderBy('id')
            ->paginate(15)->withQueryString()
            ->through(function (Plan $sheet) use ($request) {
                $annual = $sheet->parent->aipDocument;
                $blocked = null;

                if (! $annual) {
                    $blocked = 'The linked annual AIP could not be found.';
                } elseif ($sheet->created_by === $request->user()->id) {
                    $blocked = 'You cannot decide on a PPMP you created.';
                } elseif (in_array($annual->review_status, ['submitted', 'reviewed'], true)) {
                    $blocked = 'Complete the annual AIP review before deciding on this PPMP.';
                }

                return [
                    ...$sheet->only(['id', 'reference', 'version', 'total_cents']),
                    'office' => $sheet->office->name,
                    'activity_id' => $sheet->parent_id,
                    'activity' => $sheet->parent->title,
                    'annual_revision' => $annual?->revision,
                    'attested' => $annual?->review_status === 'attested',
                    'blocked' => $blocked,
                ];
            });

        return Inertia::render('ppmp-approval', [
            'year' => $year,
            'sheets' => $sheets,
            'message' => $request->session()->get('success'),
        ]);
    }

    public function decide(Request $request, Plan $ppmp, PlanningService $service): RedirectResponse
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['mayor']);
        abort_unless($ppmp->type === 'ppmp', 404);

        $data = $request->validate([
            'action' => ['required', 'in:approve,return'],
            'version' => ['required', 'integer', 'min:1'],
            'annual_revision' => ['required', 'integer', 'min:1'],
            'comment' => ['nullable', 'required_if:action,return', 'string', 'max:2000'],
            'authorize_revision' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($user, $ppmp, $data, $service) {
            Office::whereKey($ppmp->office_id)->lockForUpdate()->firstOrFail();
            $sheet = Plan::whereKey($ppmp->id)->lockForUpdate()->firstOrFail();
            $activity = $sheet->parent;

            abort_unless(
                $activity && $activity->type === 'aip' && $activity->parent_ppa_id
                && $activity->office_id === $sheet->office_id
                && $activity->fiscal_year === $sheet->fiscal_year,
                409
            );

            $annual = AipDocument::whereKey($activity->aip_document_id)
                ->where('office_id', $sheet->office_id)
                ->where('fiscal_year', $sheet->fiscal_year)
                ->lockForUpdate()->firstOrFail();

            if ($sheet->version !== (int) $data['version']
                || $annual->revision !== (int) $data['annual_revision']) {
                PlanningService::fail('The PPMP or AIP changed. Reload the queue before deciding.');
            }

            if (in_array($annual->review_status, ['submitted', 'reviewed'], true)) {
                PlanningService::fail('Complete the annual AIP review before deciding on this PPMP.');
            }

            $reviewed = AuditEvent::where('subject_type', 'plan')
                ->where('subject_id', $sheet->id)
                ->where('office_id', $sheet->office_id)
                ->where('action', 'complete_gso_review')
                ->where('metadata->version', $sheet->version)
                ->exists();

            if ($sheet->status !== 'submitted' || ! $reviewed || ! $sheet->items()->exists()) {
                PlanningService::fail('This PPMP must complete GSO review before a final decision.');
            }

            $reopen = $data['action'] === 'return' && $annual->review_status === 'attested';

            if ($reopen && ! $data['authorize_revision']) {
                PlanningService::fail('Authorize reopening the attested AIP before returning this PPMP.');
            }

            $service->transition(
                $user, $sheet, $data['action'], $sheet->version, $data['comment'] ?? null
            );

            if ($reopen) {
                $previous = [
                    'actor' => $user->name,
                    'activity' => $activity->reference.' · '.$activity->title,
                    'previous_revision' => $annual->revision,
                    'previous_status' => $annual->review_status,
                    'previous_snapshot' => $annual->submitted_snapshot,
                    'ppmp_id' => $sheet->id,
                ];

                $annual->update([
                    'review_status' => 'returned',
                    'planning_review' => 'pending',
                    'budget_review' => 'pending',
                    'revision' => $annual->revision + 1,
                ]);

                PlanningService::audit(
                    $user, 'aip_review', $annual->id, 'Returned for PPMP revision',
                    $annual->office_id, trim($data['comment']), $previous
                );
            }
        }, 3);

        return to_route('ppmp.approvals', ['year' => $ppmp->fiscal_year])
            ->with('success', $data['action'] === 'approve'
                ? 'PPMP approved. Its items are included in Consolidated PPMP.'
                : 'PPMP returned. Check the review history for the required corrections.');
    }
}