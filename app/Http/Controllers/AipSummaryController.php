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
