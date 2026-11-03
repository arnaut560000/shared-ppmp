<?php

namespace App\Http\Controllers;

use App\Models\AipDocument;
use App\Models\Budget;
use App\Models\Office;
use App\Models\Plan;
use App\Services\PlanningAccess;
use App\Services\PlanningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AipController extends Controller
{
    public function workspace(Request $request, Office $office): Response
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'between:2020,2100'],
        ]);

        $year = (int) ($data['year'] ?? now()->year);

        $document = AipDocument::where('office_id', $office->id)
            ->where('fiscal_year', $year)->first()
            ?? new AipDocument([
                'office_id' => $office->id,
                'fiscal_year' => $year,
                'title' => 'Annual Investment Plan '.$year,
            ]);

        if (! $document->exists) {
            $document->setAttribute('id', null);
        }

        return $this->page($request, $document);
    }

    public function show(Request $request, AipDocument $document): Response
    {
        return $this->page($request, $document);
    }

    public function createPpa(Request $request, Plan $plan): Response
    {
        abort_unless($plan->type === 'program', 404);
        PlanningAccess::edit($request->user(), $plan);

        return $this->page($request, $plan->aipDocument, program: $plan);
    }

    public function view(Request $request, Plan $plan): Response
    {
        abort_unless(in_array($plan->type, ['program', 'aip'], true), 404);
        PlanningAccess::view($request->user(), $plan);

        return $this->page($request, $plan->aipDocument, $plan, true);
    }

    public function edit(Request $request, Plan $plan): Response
    {
        abort_unless(in_array($plan->type, ['program', 'aip'], true), 404);
        PlanningAccess::edit($request->user(), $plan);

        return $this->page($request, $plan->aipDocument, $plan);
    }

    public function store(Request $request, PlanningService $service): RedirectResponse
    {
        $data = $this->validatedData($request);

        $plan = $data['type'] === 'program'
            ? $this->saveProgram($request, $data)
            : $service->save($request->user(), $data);

        return $this->savedRedirect($plan);
    }

    public function update(
        Request $request,
        Plan $plan,
        PlanningService $service
    ): RedirectResponse {
        abort_unless(in_array($plan->type, ['program', 'aip'], true), 404);
        PlanningAccess::edit($request->user(), $plan);

        $data = $this->validatedData($request, $plan);

        $saved = $plan->type === 'program'
            ? $this->saveProgram($request, $data, $plan)
            : $service->save($request->user(), $data, $plan);

        return $this->savedRedirect($saved);
    }

    private function saveProgram(
        Request $request,
        array $data,
        ?Plan $existing = null
    ): Plan {
        return DB::transaction(function () use ($request, $data, $existing) {
            Office::whereKey($data['office_id'])->lockForUpdate()->firstOrFail();

            if ($existing) {
                $program = Plan::whereKey($existing->id)->lockForUpdate()->firstOrFail();
                PlanningAccess::edit($request->user(), $program);

                if ($program->version !== (int) $data['version']) {
                    PlanningService::fail('This heading changed. Reload before saving.');
                }

                // Do not overwrite amounts or other fields preserved by migration.
                $program->update([
                    'reference' => $data['reference'],
                    'title' => $data['title'],
                    'version' => $program->version + 1,
                ]);
            } else {
                $document = AipDocument::firstOrCreate([
                    'office_id' => $data['office_id'],
                    'fiscal_year' => $data['fiscal_year'],
                ], ['title' => 'Annual Investment Plan '.$data['fiscal_year']]);

                $program = Plan::create([
                    'type' => 'program',
                    'aip_document_id' => $document->id,
                    'office_id' => $data['office_id'],
                    'fiscal_year' => $data['fiscal_year'],
                    'reference' => $data['reference'],
                    'title' => $data['title'],
                    'fund_source' => '',
                    'start_date' => $data['fiscal_year'].'-01-01',
                    'end_date' => $data['fiscal_year'].'-12-31',
                    'created_by' => $request->user()->id,
                    'status' => 'draft',
                    'version' => 1,
                ]);
            }

            PlanningService::audit(
                $request->user(),
                'plan',
                $program->id,
                $existing ? 'program_updated' : 'program_created',
                $program->office_id
            );

            return $program;
        });
    }

    private function validatedData(Request $request, ?Plan $editing = null): array
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['admin', 'office']);

        if (! $editing && $request->filled('aip_document_id')) {
            $request->validate(['aip_document_id' => ['required', 'integer', 'exists:aip_documents,id']]);
            $document = AipDocument::findOrFail($request->integer('aip_document_id'));
            abort_if($user->role === 'office' && $user->office_id !== $document->office_id, 403);
            $request->merge(['office_id' => $document->office_id, 'fiscal_year' => $document->fiscal_year]);
        }

        if ($editing) {
            $request->merge([
                'office_id' => $editing->office_id,
                'fiscal_year' => $editing->fiscal_year,
                'parent_ppa_id' => $editing->parent_ppa_id,
            ]);
        } elseif ($user->role === 'office') {
            abort_unless($user->office_id, 403, 'Your account needs an assigned office.');
            $request->merge(['office_id' => $user->office_id]);
        }

        $scope = $request->validate([
            'office_id' => ['required', 'integer', 'exists:offices,id'],
            'fiscal_year' => ['required', 'integer', 'between:2020,2100'],
        ]);

        $type = $editing?->type
            ?? ($request->filled('parent_ppa_id') ? 'aip' : 'program');

        $reference = Rule::unique('plans', 'reference')
            ->where('office_id', $scope['office_id'])
            ->where('fiscal_year', $scope['fiscal_year'])
            ->where('type', $type);

        if ($editing) {
            $reference->ignore($editing->id);
        }

        $rules = [
            'reference' => ['required', 'string', 'max:100', $reference],
            'title' => ['required', 'string', 'max:255'],
        ];

        if ($editing) {
            $rules['version'] = ['required', 'integer', 'min:1'];
        }

        if ($type === 'aip') {
            $year = (int) $scope['fiscal_year'];

            $rules += [
                'parent_ppa_id' => ['required', 'integer', 'exists:plans,id'],
                'fund_source' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
                'expected_output' => ['nullable', 'string', 'max:5000'],
                'sector' => ['nullable', 'string', 'max:255'],
                'sub_sector' => ['nullable', 'string', 'max:255'],
                'division' => ['nullable', 'string', 'max:255'],
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
        }

        return [...$scope, ...$request->validate($rules), 'type' => $type];
    }

    private function page(
        Request $request,
        AipDocument $document,
        ?Plan $editing = null,
        bool $readOnly = false,
        ?Plan $program = null
    ): Response {
        $user = $request->user();

        PlanningAccess::roles(
            $user,
            ['admin', 'office', 'budget', 'mayor', 'procurement', 'gso', 'inventory']
        );

        abort_if(
            $user->role === 'office' && $user->office_id !== $document->office_id,
            403
        );

        $document->load('office:id,code,name');
        $year = $document->fiscal_year;
        $canWrite = in_array($user->role, ['admin', 'office'], true);
        $program ??= $editing?->parentPpa;

        $workspaceUrl = $user->role === 'office'
            ? route('aips.index')
            : route('aips.offices.workspace', $document->office_id);

        $listUrl = $workspaceUrl.'?year='.$year;
        $isProgram = $editing ? $editing->type === 'program' : $program === null;

        $plans = Plan::where('type', 'program')
            ->where('aip_document_id', $document->id)
            ->whereNotNull('aip_document_id')
            ->with(['subPpas' => fn ($query) => $query
                ->where('office_id', $document->office_id)
                ->where('fiscal_year', $year)
                ->where('aip_document_id', $document->id)
                ->orderBy('id')])
            ->orderByDesc('id')
            ->paginate(15)
            ->withPath($workspaceUrl)
            ->appends(['year' => $year])
            ->through(fn (Plan $heading) => [
                'id' => $heading->id,
                'reference' => $heading->reference,
                'title' => $heading->title,
                'total_cents' => (int) $heading->subPpas->sum('total_cents'),
                'legacy_total_cents' => $heading->total_cents,
                'children' => $heading->subPpas->map(fn (Plan $ppa) => [
                    'id' => $ppa->id,
                    'reference' => $ppa->reference,
                    'title' => $ppa->title,
                    'status' => $ppa->status,
                    'total_cents' => $ppa->total_cents,
                    'can_edit' => $canWrite
                        && in_array($ppa->status, ['draft', 'returned'], true),
                ])->values(),
            ]);

        $reference = $editing?->reference ?? '';

        if (! $editing && $program && strlen($program->reference) <= 90) {
            $number = $program->subPpas()->count() + 1;

            do {
                $reference = $program->reference.'-'.str_pad((string) $number++, 3, '0', STR_PAD_LEFT);
            } while (Plan::where('type', 'aip')
                ->where('office_id', $document->office_id)
                ->where('fiscal_year', $year)
                ->where('reference', $reference)->exists());
        }

        $initial = [
            'aip_document_id' => (string) $document->id,
            'office_id' => (string) $document->office_id,
            'fiscal_year' => (string) $year,
            'parent_ppa_id' => (string) ($program?->id ?? ''),
            'reference' => $reference,
            'title' => $editing?->title ?? '',
            'fund_source' => $editing?->fund_source ?? '',
            'description' => $editing?->description ?? '',
            'expected_output' => $editing?->expected_output ?? '',
            'sector' => $editing?->sector ?? '',
            'sub_sector' => $editing?->sub_sector ?? '',
            'division' => $editing?->division ?? '',
            'start_date' => $editing?->start_date ?? "{$year}-01-01",
            'end_date' => $editing?->end_date ?? "{$year}-12-31",
            'version' => (string) ($editing?->version ?? 0),
            'workflow' => '',
        ];

        foreach (['ps', 'mooe', 'fe', 'co'] as $field) {
            $initial[$field] = bcdiv(
                (string) ($editing?->getAttribute($field.'_cents') ?? 0),
                '100',
                2
            );
        }

        $allocated = (int) $document->ppas()->sum('total_cents');

        $budget = Budget::where('office_id', $document->office_id)
            ->where('fiscal_year', $year)->first();

        return Inertia::render('ppas', [
            'document' => $document,
            'year' => $year,
            'canWrite' => $canWrite,
            'listUrl' => $listUrl,
            'workspaceUrl' => $workspaceUrl,
            'plans' => $plans,
            'budget' => [
                'ceiling_cents' => $budget?->ceiling_cents,
                'allocated_cents' => $allocated,
                'remaining_cents' => $budget ? $budget->ceiling_cents - $allocated : null,
            ],
            'isProgram' => $isProgram,
            'program' => $program?->only(['id', 'reference', 'title']),
            'editingId' => $editing?->id,
            'readOnly' => $readOnly,
            'editorVisible' => $editing !== null
                || ($canWrite && ($program !== null || $request->boolean('create'))),
            'initial' => $initial,
            'message' => $request->session()->get('success'),
        ]);
    }

    private function savedRedirect(Plan $plan): RedirectResponse
    {
        $rootId = $plan->parent_ppa_id ?? $plan->id;

        $before = Plan::where('type', 'program')
            ->where('aip_document_id', $plan->aip_document_id)
            ->where('id', '>', $rootId)->count();

        $parameters = ['document' => $plan->aip_document_id];
        if ($before >= 15) {
            $parameters['page'] = intdiv($before, 15) + 1;
        }

        return to_route('aips.documents.show', $parameters)->with('success', $plan->type === 'program' ? 'Program heading saved.' : 'PPA saved.');
    }
}
