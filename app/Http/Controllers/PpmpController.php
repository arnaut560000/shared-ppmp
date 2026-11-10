<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePpmpRequest;
use App\Models\CatalogueItem;
use App\Models\Plan;
use App\Services\OfficeBudgetService;
use App\Services\PlanningAccess;
use App\Services\PlanningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PpmpController extends Controller
{
    public function show(Request $request, Plan $ppa): Response
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['admin', 'office', 'budget', 'mayor', 'gso', 'procurement', 'inventory']);
        PlanningAccess::view($user, $ppa);
        abort_unless($ppa->type === 'aip' && $ppa->parent_ppa_id !== null, 404);
        $request->validate(['sheet' => ['nullable', 'integer', 'min:1'], 'new' => ['nullable', 'boolean']]);

        $query = $ppa->procurementLists()->where('office_id', $ppa->office_id)->where('fiscal_year', $ppa->fiscal_year);
        $sheets = (clone $query)->orderBy('id')->get(['id', 'reference', 'status', 'total_cents']);
        $sheetId = $request->integer('sheet') ?: ($request->boolean('new') ? null : $sheets->first()?->id);
        $sheet = $sheetId ? (clone $query)->with('items')->whereKey($sheetId)->firstOrFail() : null;
        $legacy = $sheet && $sheet->items->contains(fn ($item) => count($item->monthly_quantities ?? []) !== 12);
        $canPrepare = in_array($user->role, ['office', 'admin'], true) && $ppa->permitsProcurementDrafts();
        $budget = OfficeBudgetService::summary($ppa->office_id, $ppa->fiscal_year);
        $available = $budget['remaining_cents'] === null ? null
            : $budget['remaining_cents'] + (int) ($sheet?->items->sum('total_cents') ?? 0);

        $number = $sheets->count() + 1;
        do {
            $reference = sprintf('PPMP-%d-%03d', $ppa->id, $number++);
        } while ($sheets->contains('reference', $reference));

        return Inertia::render('ppmp', [
            'ppa' => $ppa->only(['id', 'reference', 'title', 'fiscal_year', 'fund_source', 'status']),
            'office' => $ppa->office->name,
            'summaryUrl' => route('aips.documents.summary', $ppa->aip_document_id),
            'backUrl' => route('aips.documents.show', $ppa->aip_document_id),
            'sheets' => $sheets,
            'selected' => $sheet ? [...$sheet->only(['id', 'reference', 'version', 'status']), 'items' => $sheet->items] : null,
            'reference' => $sheet?->reference ?? $reference,
            'budget' => $budget,
            'available_for_sheet_cents' => $available,
            // Older clients render two columns. Both draw on the same office balance.
            'available' => ['MOOE' => $available ?? 0, 'CO' => $available ?? 0],
            'catalogue' => CatalogueItem::where('active', true)->whereIn('expense_class', ['MOOE', 'CO'])
                ->orderBy('name')->get(['id', 'code', 'name', 'specification', 'unit', 'category', 'expense_class', 'price_cents']),
            'canPrepare' => $canPrepare,
            'canEdit' => $canPrepare && ! $legacy && (! $sheet || in_array($sheet->status, ['draft', 'returned'], true)),
            'legacy' => (bool) $legacy,
            'message' => $request->session()->get('success'),
        ]);
    }

    public function store(SavePpmpRequest $request, Plan $ppa, PlanningService $service): RedirectResponse
    {
        return $this->save($request, $ppa, $service);
    }

    public function update(SavePpmpRequest $request, Plan $ppa, Plan $ppmp, PlanningService $service): RedirectResponse
    {
        return $this->save($request, $ppa, $service, $ppmp);
    }

    private function save(SavePpmpRequest $request, Plan $ppa, PlanningService $service, ?Plan $sheet = null): RedirectResponse
    {
        $saved = $service->save($request->user(), [
            ...$request->validated(),
            'type' => 'ppmp', 'parent_id' => $ppa->id, 'office_id' => $ppa->office_id,
            'fiscal_year' => $ppa->fiscal_year, 'title' => $sheet?->title ?? $ppa->title,
            'fund_source' => $ppa->fund_source, 'start_date' => $ppa->start_date, 'end_date' => $ppa->end_date,
        ], $sheet);

        return to_route('ppmp.show', ['ppa' => $ppa->id, 'sheet' => $saved->id])
            ->with('success', 'Monthly PPMP draft saved.');
    }
}
