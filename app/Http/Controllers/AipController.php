<?php

namespace App\Http\Controllers;

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
    public function index(Request $request): Response
    {
        return $this->page($request);
    }

    public function edit(Request $request, Plan $plan): Response
    {
        abort_unless($plan->type === 'aip', 404);
        PlanningAccess::edit($request->user(), $plan);

        return $this->page($request, $plan);
    }

    public function store(
        Request $request,
        PlanningService $service
    ): RedirectResponse {
        $data = $this->validatedData($request);

        $plan = $service->save($request->user(), $data);

        return to_route('aips.index', ['year' => $plan->fiscal_year])
            ->with('success', 'AIP draft created successfully.');
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

        return to_route('aips.index', ['year' => $plan->fiscal_year])
            ->with('success', 'AIP draft updated successfully.');
    }

    private function page(Request $request, ?Plan $editing = null): Response
    {
        $user = $request->user();

        PlanningAccess::roles($user, [
            'admin', 'office', 'budget', 'mayor',
            'procurement', 'gso', 'inventory',
        ]);

        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'between:2020,2100'],
        ]);

        $year = $editing?->fiscal_year
            ?? (int) ($validated['year'] ?? now()->year);

        $canWrite = in_array($user->role, ['admin', 'office'], true);

        $offices = Office::query()
            ->when(
                $user->role === 'office',
                fn ($query) => $query->whereKey($user->office_id ?? 0)
            )
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $plans = Plan::query()
            ->visibleTo($user)
            ->where('type', 'aip')
            ->where('fiscal_year', $year)
            ->with('office:id,code')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withPath(route('aips.index'))
            ->appends(['year' => $year])
            ->through(fn (Plan $plan) => [
                'id' => $plan->id,
                'reference' => $plan->reference,
                'title' => $plan->title,
                'office' => $plan->office?->code,
                'status' => $plan->status,
                'total_cents' => $plan->total_cents,
                'can_edit' => $canWrite
                    && in_array($plan->status, ['draft', 'returned'], true),
            ]);

        $initial = [
            'office_id' => $editing
                ? (string) $editing->office_id
                : ($offices->count() === 1 ? (string) $offices->first()->id : ''),
            'fiscal_year' => (string) $year,
            'reference' => $editing?->reference ?? '',
            'title' => $editing?->title ?? '',
            'description' => $editing?->description ?? '',
            'fund_source' => $editing?->fund_source ?? '',
            'expected_output' => $editing?->expected_output ?? '',
            'start_date' => $editing?->start_date ?? "{$year}-01-01",
            'end_date' => $editing?->end_date ?? "{$year}-12-31",
            'version' => (string) ($editing?->version ?? 0),
            'workflow' => '',
        ];

        foreach (['ps', 'mooe', 'fe', 'co'] as $field) {
            $initial[$field] = bcdiv(
                (string) ($editing?->getAttribute($field.'_cents') ?? 0),
                '100',
                2,
            );
        }

        return Inertia::render('aips', [
            'year' => $year,
            'canWrite' => $canWrite,
            'offices' => $offices,
            'plans' => $plans,
            'editingId' => $editing?->id,
            'initial' => $initial,
            'message' => $request->session()->get('success'),
        ]);
    }

    private function validatedData(
        Request $request,
        ?Plan $editing = null
    ): array {
        $user = $request->user();
        PlanningAccess::roles($user, ['admin', 'office']);

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