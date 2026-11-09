<?php

namespace App\Http\Controllers;

use App\Models\AipDocument;
use App\Models\Budget;
use App\Services\PlanningAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AipSummaryController extends Controller
{
    public function __invoke(Request $request, AipDocument $document): Response
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['admin', 'office', 'budget', 'mayor', 'procurement', 'gso', 'inventory']);
        abort_if($user->role === 'office' && $user->office_id !== $document->office_id, 403);
        $document->load('office:id,name,code');
        // Read the complete annual document, independent of the workspace's current page.
        $programs = $document->programs()->orderBy('id')->with([
            'subPpas' => fn ($q) => $q->where('aip_document_id', $document->id)
                ->where('office_id', $document->office_id)->where('fiscal_year', $document->fiscal_year)
                ->orderBy('id')->with(['procurementLists' => fn ($lists) => $lists
                ->where('office_id', $document->office_id)->where('fiscal_year', $document->fiscal_year)
                ->select('id', 'parent_id', 'total_cents')->withCount('items')]),
        ])->get()->map(fn ($program) => [
            'id' => $program->id, 'title' => $program->title, 'reference' => $program->reference,
            'ppas' => $program->subPpas->map(fn ($ppa) => [
                ...$ppa->only(['id', 'title', 'reference', 'status', 'total_cents', 'expected_output', 'performance_indicator', 'physical_target']),
                'item_count' => (int) $ppa->procurementLists->sum('items_count'),
                'procurement_cents' => (int) $ppa->procurementLists->sum('total_cents'),
            ])->values(),
        ])->values();
        $ceiling = Budget::where('office_id', $document->office_id)
            ->where('fiscal_year', $document->fiscal_year)->value('ceiling_cents');

        return Inertia::render('aip-summary', [
            'document' => $document, 'programs' => $programs,
            'year' => $document->fiscal_year, 'listUrl' => route('aips.documents.show', $document),
            'ceiling' => $ceiling === null ? null : (int) $ceiling,
        ]);
    }
}
