<?php

namespace App\Http\Controllers;

use App\Models\AipDocument;
use App\Models\Office;
use App\Models\Plan;
use App\Services\PlanningAccess;
use App\Services\PlanningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AipController extends Controller
{
    public function workspace(Request $request, Office $office): Response
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['admin', 'office']);
        abort_if($user->role === 'office' && $user->office_id !== $office->id, 403);
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2020,2100']]);
        $year = (int) ($data['year'] ?? now()->year);
        // Reading a new year must not create empty database records. The save
        // service creates the annual container atomically with the first PPA.
        $document = AipDocument::where('office_id', $office->id)->where('fiscal_year', $year)->first()
            ?? new AipDocument(['office_id' => $office->id, 'fiscal_year' => $year, 'title' => 'Annual Investment Plan '.$year]);

        if (! $document->exists) {
            $document->setAttribute('id', null);
        }

        return $this->page($request, $document);
    }

    public function createSub(Request $request, Plan $plan): Response
    {
        abort_unless(
            $plan->type === 'aip' && $plan->parent_ppa_id === null,
            404
        );

        PlanningAccess::edit($request->user(), $plan);

        return $this->page(
            $request,
            $plan->aipDocument,
            null,
            false,
            $plan
        );

    }

    public function show(Request $request, AipDocument $document): Response
    {
        return $this->page($request, $document);
    }

    public function view(Request $request, Plan $plan): Response
    {
        abort_unless($plan->type === 'aip', 404);
        PlanningAccess::view($request->user(), $plan);

        return $this->page($request, $plan->aipDocument, $plan, true);
    }

    public function edit(Request $request, Plan $plan): Response
    {
        abort_unless($plan->type === 'aip', 404);
        PlanningAccess::edit($request->user(), $plan);

        return $this->page($request, $plan->aipDocument, $plan);
    }

    public function store(
        Request $request,
        PlanningService $service
    ): RedirectResponse {
        $data = $this->validatedData($request);

        $plan = $service->save($request->user(), $data);

        return $this->savedRedirect($plan, $plan->parent_ppa_id ? 'Sub-PPA draft created successfully.' : 'PPA draft created successfully.');
    }

    public function update(
        Request $request,
        Plan $plan,
        PlanningService $service
    ): RedirectResponse {
        abort_unless($plan->type === 'aip', 404);
        PlanningAccess::edit($request->user(), $plan);

        $data = $this->validatedData($request, $plan);
        $service->save($request->user(), $data, $plan);

        return $this->savedRedirect($plan, 'PPA draft updated successfully.');
    }

    private function page(
        Request $request,
        AipDocument $document,
        ?Plan $editing = null,
        bool $readOnly = false,
        ?Plan $parentPpa = null
    ): Response {
        $user = $request->user();
        PlanningAccess::roles($user, ['admin', 'office', 'budget', 'mayor', 'procurement', 'gso', 'inventory']);
        abort_if($user->role === 'office' && $user->office_id !== $document->office_id, 403);
        $document->load('office:id,code,name');
        $year = $document->fiscal_year;
        $canWrite = in_array($user->role, ['admin', 'office'], true);
        $parentPpa ??= $editing?->parentPpa;
        $workspaceUrl = $user->role === 'office' ? route('aips.index') : route('aips.offices.workspace', $document->office_id);
        $listUrl = $workspaceUrl.'?year='.$year;

        $row = static fn (Plan $plan, bool $parentEditable = true): array => [
            'id' => $plan->id,
            'reference' => $plan->reference,
            'title' => $plan->title,
            'program_name' => $plan->program_name,
            'office' => $plan->office?->code,
            'status' => $plan->status,
            'total_cents' => $plan->total_cents,
            'parent_ppa_id' => $plan->parent_ppa_id,
            'children' => [],
            'can_edit' => $canWrite && $parentEditable && in_array($plan->status, ['draft', 'returned'], true),
        ];

        $plans = Plan::query()->visibleTo($user)
            ->where('type', 'aip')->where('aip_document_id', $document->id)
            ->whereNotNull('aip_document_id')->whereNull('parent_ppa_id')
            ->with([
                'office:id,code',
                'subPpas' => fn ($query) => $query->where('aip_document_id', $document->id)
                    ->with('office:id,code')->orderBy('id'),
            ])
            ->orderByDesc('id')->paginate(15)->withPath($workspaceUrl)->appends(['year' => $year])
            ->through(function (Plan $plan) use ($row) {
                $result = $row($plan);
                $result['children'] = $plan->subPpas->map(
                    fn (Plan $child) => $row($child, in_array($plan->status, ['draft', 'returned'], true))
                )->values();

                return $result;
            });

        $reference = $editing?->reference ?? '';
        if (! $editing && $parentPpa && strlen($parentPpa->reference) <= 96) {
            $number = $parentPpa->subPpas()->count() + 1;
            do {
                $reference = $parentPpa->reference.'-'.str_pad((string) $number++, 3, '0', STR_PAD_LEFT);
            } while (Plan::where('type', 'aip')->where('office_id', $document->office_id)
                ->where('fiscal_year', $year)->where('reference', $reference)->exists());
        }

        $initial = [
            'aip_document_id' => (string) $document->id,
            'parent_ppa_id' => (string) ($parentPpa?->id ?? ''),
            'program_name' => $editing?->program_name ?? $parentPpa?->title ?? '',
            'sector' => $editing?->sector ?? $parentPpa?->sector ?? '',
            'sub_sector' => $editing?->sub_sector ?? $parentPpa?->sub_sector ?? '',
            'division' => $editing?->division ?? $parentPpa?->division ?? '',
            'office_id' => (string) $document->office_id,
            'fiscal_year' => (string) $year,
            'reference' => $reference,
            'title' => $editing?->title ?? '',
            'description' => $editing?->description ?? '',
            'fund_source' => $editing?->fund_source ?? $parentPpa?->fund_source ?? '',
            'expected_output' => $editing?->expected_output ?? '',
            'start_date' => $editing?->start_date ?? $parentPpa?->start_date ?? "{$year}-01-01",
            'end_date' => $editing?->end_date ?? $parentPpa?->end_date ?? "{$year}-12-31",
            'version' => (string) ($editing?->version ?? 0),
            'workflow' => '',
        ];
        $available = [];
        foreach (['ps', 'mooe', 'fe', 'co'] as $field) {
            $initial[$field] = bcdiv((string) ($editing?->getAttribute($field.'_cents') ?? 0), '100', 2);
            if ($parentPpa) {
                $used = $parentPpa->subPpas()->when($editing, fn ($q) => $q->where('id', '!=', $editing->id))->sum($field.'_cents');
                $available[$field] = $parentPpa->getAttribute($field.'_cents') - (int) $used;
            }
        }

        return Inertia::render('ppas', [
            'document' => $document,
            'listUrl' => $listUrl,
            'workspaceUrl' => $workspaceUrl,
            'departmentUser' => $user->role === 'office',
            'startCreating' => $canWrite && (($parentPpa !== null && $editing === null) || $request->boolean('create')),
            'parentPpa' => $parentPpa ? [...$parentPpa->only(['id', 'reference', 'title']), 'available' => $available] : null,
            'readOnly' => $readOnly,
            'totals' => collect(['ps', 'mooe', 'fe', 'co', 'total'])->mapWithKeys(
                fn ($key) => [$key => (int) $document->mainPpas()->sum($key.'_cents')]
            ),
            'year' => $year,
            'canWrite' => $canWrite,
            'offices' => collect([$document->office]),
            'plans' => $plans,
            'editingId' => $editing?->id,
            'initial' => $initial,
            'message' => $request->session()->get('success'),
        ]);
    }

    private function savedRedirect(Plan $plan, string $message): RedirectResponse
    {
        $rootId = $plan->parent_ppa_id ?? $plan->id;
        $before = Plan::where('type', 'aip')->where('aip_document_id', $plan->aip_document_id)
            ->whereNull('parent_ppa_id')->where('id', '>', $rootId)->count();
        $parameters = ['document' => $plan->aip_document_id];
        if ($before >= 15) {
            $parameters['page'] = intdiv($before, 15) + 1;
        }

        return to_route('aips.documents.show', $parameters)->with('success', $message);
    }

    private function validatedData(
        Request $request,
        ?Plan $editing = null
    ): array {
        $user = $request->user();
        PlanningAccess::roles($user, ['admin', 'office']);

        if (! $editing && $request->filled('aip_document_id')) {
            $request->validate(['aip_document_id' => ['required', 'integer', 'exists:aip_documents,id']]);
            $document = AipDocument::findOrFail($request->integer('aip_document_id'));
            abort_if($user->role === 'office' && $user->office_id !== $document->office_id, 403);
            $request->merge(['office_id' => $document->office_id, 'fiscal_year' => $document->fiscal_year]);
        }

        if ($editing) {
            // Office and fiscal year remain fixed after creation.
            $request->merge([
                'office_id' => $editing->office_id,
                'fiscal_year' => $editing->fiscal_year,
            ]);
        } elseif ($user->role === 'office') {
            abort_unless($user->office_id, 403, 'Your account needs an assigned office.');

            // Office users cannot create a plan for another office.
            $request->merge(['office_id' => $user->office_id]);
        }

        $scope = $request->validate([
            'office_id' => ['required', 'integer', 'exists:offices,id'],
            'fiscal_year' => ['required', 'integer', 'between:2020,2100'],
        ]);

        $year = (int) $scope['fiscal_year'];

        $referenceRule = Rule::unique('plans', 'reference')
            ->where('office_id', $scope['office_id'])
            ->where('fiscal_year', $year)
            ->where('type', 'aip');

        if ($editing) {
            $referenceRule->ignore($editing->id);
        }

        $rules = [
            'parent_ppa_id' => ['nullable', 'integer', 'exists:plans,id'],
            'program_name' => ['nullable', 'string', 'max:255'],
            'sector' => ['nullable', 'string', 'max:255'],
            'sub_sector' => ['nullable', 'string', 'max:255'],
            'division' => ['nullable', 'string', 'max:255'],
            'reference' => ['required', 'string', 'max:100', $referenceRule],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'fund_source' => ['required', 'string', 'max:255'],
            'expected_output' => ['nullable', 'string', 'max:5000'],
            'start_date' => [
                'required', 'date_format:Y-m-d',
                "after_or_equal:{$year}-01-01",
                "before_or_equal:{$year}-12-31",
            ],
            'end_date' => [
                'required', 'date_format:Y-m-d',
                'after_or_equal:start_date',
                "before_or_equal:{$year}-12-31",
            ],
        ];

        foreach (['ps', 'mooe', 'fe', 'co'] as $field) {
            $rules[$field] = [
                'required', 'numeric', 'min:0',
                'max:10000000000', 'decimal:0,2',
            ];
        }

        if ($editing) {
            $rules['version'] = ['required', 'integer', 'min:1'];
        }

        return [
            ...$scope,
            ...$request->validate($rules),
            'type' => 'aip',
        ];
    }
}
