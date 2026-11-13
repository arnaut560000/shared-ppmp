<?php

namespace App\Http\Controllers;

use App\Models\AipDocument;
use App\Models\AuditEvent;
use App\Models\Office;
use App\Services\PlanningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use App\Services\OfficeBudgetService;
use App\Services\PlanningAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AipSummaryController extends Controller
{
    public function __invoke(Request $request, AipDocument $document): Response
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['admin', 'office', 'budget', 'mayor', 'procurement', 'gso', 'inventory', 'mpdc']);
        abort_if($user->role === 'office' && $user->office_id !== $document->office_id, 403);
        $document->load('office:id,name,code');
        abort_if($user->role === 'mpdc' && $document->review_status === 'draft', 403);
        $contents = $document->isReviewLocked() ? $document->submitted_snapshot : $this->contents($document);
        $history = AuditEvent::where('subject_type', 'aip_review')->where('subject_id', $document->id)
            ->orderByDesc('id')->get()->map(fn ($event) => [
                'id' => $event->id, 'action' => $event->action, 'comment' => $event->comment,
                'actor' => $event->metadata['actor'], 'activity' => $event->metadata['activity'] ?? null,
                'date' => $event->created_at,
            ]);

        return Inertia::render('aip-summary', [
            ...$contents, 'document' => $document, 'history' => $history,
            'summaryToken' => $this->token($document, $contents),
            'year' => $document->fiscal_year, 'listUrl' => route('aips.documents.show', $document),
        ]);
    }

    public function decide(Request $request, AipDocument $document): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:submit,complete,return,attest'],
            'revision' => ['required', 'integer', 'min:1'],
            'summaryToken' => ['required', 'string', 'size:64'],
            'comment' => ['nullable', 'required_if:action,return', 'string', 'max:2000'],
            'activity_id' => ['nullable', 'integer'],
        ]);
        $user = $request->user();
        $action = $data['action'];
        PlanningAccess::roles($user, match ($action) {
            'submit' => ['office'],
            'attest' => ['mayor'],
            default => ['mpdc', 'budget'],
        });
        abort_if($user->role === 'office' && $user->office_id !== $document->office_id, 403);

        DB::transaction(function () use ($document, $user, $action, $data) {
            Office::whereKey($document->office_id)->lockForUpdate()->firstOrFail();
            $annual = AipDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($annual->revision !== (int) $data['revision']) {
                PlanningService::fail('The AIP changed. Reload its summary before continuing.');
            }
            $activity = null;
            if ($action === 'submit') {
                if (! in_array($annual->review_status, ['draft', 'returned'], true)) {
                    PlanningService::fail('Only a draft or returned AIP can be submitted.');
                }
                $contents = $this->contents($annual);
                if (! hash_equals($this->token($annual, $contents), $data['summaryToken'])) {
                    PlanningService::fail('The planning figures changed. Reload and review the current summary.');
                }
                if (! collect($contents['programs'])->pluck('ppas')->flatten(1)->count()) {
                    PlanningService::fail('Add at least one Sub PPA before submitting.');
                }
                $annual->submitted_snapshot = $contents;
                $annual->planning_review = $annual->budget_review = 'pending';
                $annual->review_status = 'submitted';
                $event = 'Submitted';
            } elseif ($action === 'attest') {
                if ($annual->review_status !== 'reviewed' || $annual->planning_review !== 'complete' || $annual->budget_review !== 'complete') {
                    PlanningService::fail('Both reviews must be complete before attestation.');
                }
                $annual->review_status = 'attested';
                $event = 'Attested';
            } else {
                $field = $user->role === 'mpdc' ? 'planning_review' : 'budget_review';
                if ($annual->review_status !== 'submitted' || $annual->$field !== 'pending') {
                    PlanningService::fail('This review is no longer pending.');
                }
                if (isset($data['activity_id'])) {
                    $row = $annual->ppas()->whereKey($data['activity_id'])->first();
                    if (! $row || $action !== 'return') {
                        PlanningService::fail('Select a Sub PPA in this AIP for the return comment.');
                    }
                    $activity = $row->reference.' · '.$row->title;
                }
                $annual->$field = $action === 'return' ? 'returned' : 'complete';
                if ($action === 'return') {
                    $annual->review_status = 'returned';
                } elseif ($annual->planning_review === 'complete' && $annual->budget_review === 'complete') {
                    $annual->review_status = 'reviewed';
                }
                $event = ($user->role === 'mpdc' ? 'Planning review' : 'Budget review')
                    .($action === 'return' ? ' returned' : ' completed');
            }
            $annual->revision++;
            $annual->save();
            PlanningService::audit($user, 'aip_review', $annual->id, $event, $annual->office_id,
                $action === 'return' ? trim($data['comment']) : null, ['actor' => $user->name, 'activity' => $activity]);
        }, 3);

        return to_route('aips.documents.summary', $document);
    }

    private function token(AipDocument $document, array $contents): string
    {
        return hash('sha256', json_encode([$document->revision, $contents], JSON_THROW_ON_ERROR));
    }

    private function contents(AipDocument $document): array
    {
        // Read the complete annual document, independent of the workspace's current page.
        $procurement = OfficeBudgetService::procurementByPpa($document->office_id, $document->fiscal_year);
        $programs = $document->programs()->orderBy('id')->with([
            'subPpas' => fn ($q) => $q->where('aip_document_id', $document->id)
                ->where('office_id', $document->office_id)->where('fiscal_year', $document->fiscal_year)
                ->orderBy('id')->with(['procurementLists' => fn ($lists) => $lists
                ->where('office_id', $document->office_id)->where('fiscal_year', $document->fiscal_year)
                ->select('id', 'parent_id', 'total_cents')->withCount('items')]),
        ])->get()->map(fn ($program) => [
            'id' => $program->id, 'title' => $program->title, 'reference' => $program->reference,
            'ppas' => $program->subPpas->map(fn ($ppa) => [
                ...$ppa->only(['id', 'title', 'reference', 'status', 'expected_output', 'performance_indicator', 'physical_target']),
                'total_cents' => OfficeBudgetService::ppaTotal($ppa, $procurement),
                'item_count' => (int) $ppa->procurementLists->sum('items_count'),
                'procurement_cents' => (int) ($procurement->get($ppa->id)->mooe ?? 0) + (int) ($procurement->get($ppa->id)->co ?? 0),
            ])->values(),
        ])->values();
        $budget = OfficeBudgetService::summary($document->office_id, $document->fiscal_year);

        return ['programs' => $programs->toArray(), 'budget' => $budget, 'ceiling' => $budget['ceiling_cents']];
    }
}
