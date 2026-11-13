<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePpmpRequest;
use App\Models\AuditEvent;
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
    public function index(Request $request): Response
    {
        $user = $request->user();
        $queue = $request->routeIs('ppmp.reviews');
        PlanningAccess::roles($user, [$queue ? 'gso' : 'office']);
        abort_unless($queue || $user->office_id, 403, 'Your account needs an assigned office.');

        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2020,2100']]);
        $year = (int) ($data['year'] ?? now()->year);

        $sheets = Plan::query()
            ->join('plans as activity', 'activity.id', '=', 'plans.parent_id')
            ->join('plans as heading', 'heading.id', '=', 'activity.parent_ppa_id')
            ->where('plans.type', 'ppmp')
            ->where('activity.type', 'aip')
            ->where('heading.type', 'program')
            ->join('offices', 'offices.id', '=', 'plans.office_id')
            ->when($queue, fn ($q) => $q->where('plans.status', 'pending_gso')->orderBy('plans.submitted_at'),
                fn ($q) => $q->where('plans.office_id', $user->office_id))
            ->where('plans.fiscal_year', $year)
            ->whereColumn('activity.office_id', 'plans.office_id')
            ->whereColumn('activity.fiscal_year', 'plans.fiscal_year')
            ->whereColumn('heading.office_id', 'plans.office_id')
            ->whereColumn('heading.fiscal_year', 'plans.fiscal_year')
            ->orderBy('heading.reference')
            ->orderBy('activity.reference')
            ->orderBy('plans.reference')
            ->orderBy('plans.id')
            ->paginate(20, [
                'plans.id', 'plans.reference', 'plans.status', 'plans.total_cents', 'offices.name as office_name',
                'activity.id as activity_id', 'activity.title as activity_title', 'heading.title as heading_title',
            ])->appends(['year' => $year]);

        return Inertia::render('my-ppmps', [
            'office' => $queue ? null : $user->office->name,
            'reviewQueue' => $queue,
            'year' => $year,
            'sheets' => $sheets,
        ]);
    }

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
        $canPrepare = in_array($user->role, ['office', 'admin'], true) && $ppa->permitsProcurementDrafts()
            && ! in_array($ppa->aipDocument?->review_status, ['submitted', 'reviewed'], true);
        $budget = OfficeBudgetService::summary($ppa->office_id, $ppa->fiscal_year);
        $available = $budget['remaining_cents'] === null ? null
            : $budget['remaining_cents'] + (int) ($sheet?->items->sum('total_cents') ?? 0);
        $reference = $sheet?->reference;
        if ($reference === null) {
            $officeSheets = Plan::where('type', 'ppmp')
                ->where('office_id', $ppa->office_id)
                ->where('fiscal_year', $ppa->fiscal_year);
            $number = $sheets->count() + 1;
            do {
                $reference = sprintf('PPMP-%d-%03d', $ppa->id, $number++);
            } while ((clone $officeSheets)->where('reference', $reference)->exists());
        }

        return Inertia::render('ppmp', [
            'ppa' => $ppa->only(['id', 'reference', 'title', 'fiscal_year', 'fund_source', 'status']),
            'office' => $ppa->office->name,
            'summaryUrl' => route('aips.documents.summary', $ppa->aip_document_id),
            'backUrl' => route('aips.documents.show', $ppa->aip_document_id),
            'sheets' => $sheets,
            'selected' => $sheet ? [...$sheet->only(['id', 'reference', 'version', 'status']), 'items' => $sheet->items] : null,
            'reference' => $reference,
            'budget' => $budget,
            'available_for_sheet_cents' => $available,
            // Older clients render two columns. Both draw on the same office balance.
            'available' => ['MOOE' => $available ?? 0, 'CO' => $available ?? 0],
            'catalogue' => CatalogueItem::where('active', true)->whereIn('expense_class', ['MOOE', 'CO'])
                ->orderBy('name')->get(['id', 'code', 'name', 'specification', 'unit', 'category', 'expense_class', 'price_cents']),
            'canSubmitToGso' => $user->role === 'office' && $canPrepare && ! $legacy && $sheet
                && in_array($sheet->status, ['draft', 'returned'], true) && $sheet->items->isNotEmpty(),
            'canReviewForGso' => $user->role === 'gso' && $sheet?->status === 'pending_gso' && $sheet->created_by !== $user->id,
            'reviewHistory' => $sheet ? AuditEvent::where('subject_type', 'plan')->where('subject_id', $sheet->id)
                ->where('office_id', $sheet->office_id)->whereIn('action', ['submit_to_gso', 'complete_gso_review', 'return_from_gso'])
                ->latest('id')->get(['id', 'action', 'comment', 'created_at']) : [],
            'canPrepare' => $canPrepare,
            'canEdit' => $canPrepare && ! $legacy && (! $sheet || in_array($sheet->status, ['draft', 'returned'], true)),
            'legacy' => (bool) $legacy,
            'message' => $request->session()->get('success'),
        ]);
    }

    public function review(Request $request, Plan $ppa, Plan $ppmp, PlanningService $service): RedirectResponse
    {
        PlanningAccess::view($request->user(), $ppa);
        abort_unless($ppa->type === 'aip' && $ppa->parent_ppa_id && $ppmp->type === 'ppmp'
            && $ppmp->parent_id === $ppa->id && $ppmp->office_id === $ppa->office_id && $ppmp->fiscal_year === $ppa->fiscal_year, 404);
        $data = $request->validate([
            'action' => ['required', 'in:submit_to_gso,complete_gso_review,return_from_gso'],
            'version' => ['required', 'integer', 'min:1'],
            'comment' => ['nullable', 'required_if:action,return_from_gso', 'string', 'max:2000'],
        ]);
        PlanningAccess::roles($request->user(), [$data['action'] === 'submit_to_gso' ? 'office' : 'gso']);
        $service->transition($request->user(), $ppmp, $data['action'], (int) $data['version'], $data['comment'] ?? null);

        return to_route('ppmp.show', ['ppa' => $ppa->id, 'sheet' => $ppmp->id])->with('success', match ($data['action']) {
            'submit_to_gso' => 'PPMP submitted to GSO.',
            'return_from_gso' => 'PPMP returned to the department for revision.',
            'complete_gso_review' => 'GSO review complete. The PPMP is awaiting approval.',
        });
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
