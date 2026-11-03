<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Office;
use App\Models\Plan;
use App\Services\PlanningAccess;
use App\Services\PlanningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OfficeBudgetController extends Controller
{
    public function index(Request $request): Response
    {
        PlanningAccess::roles($request->user(), ['admin', 'budget']);

        $data = $request->validate([
            'year' => ['nullable', 'integer', 'between:2020,2100'],
        ]);

        $year = (int) ($data['year'] ?? now()->year);

        $offices = Office::orderBy('name')->get()->map(function (Office $office) use ($year) {
            $budget = Budget::where('office_id', $office->id)
                ->where('fiscal_year', $year)->first();

            $allocated = (int) Plan::where('type', 'aip')
                ->where('office_id', $office->id)
                ->where('fiscal_year', $year)
                ->sum('total_cents');

            return [
                'id' => $office->id,
                'code' => $office->code,
                'name' => $office->name,
                'ceiling_cents' => $budget?->ceiling_cents,
                'allocated_cents' => $allocated,
                'remaining_cents' => $budget
                    ? $budget->ceiling_cents - $allocated
                    : null,
            ];
        });

        return Inertia::render('office-budgets', [
            'year' => $year,
            'offices' => $offices,
            'message' => $request->session()->get('success'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        PlanningAccess::roles($user, ['admin', 'budget']);

        $data = $request->validate([
            'office_id' => ['required', 'integer', 'exists:offices,id'],
            'fiscal_year' => ['required', 'integer', 'between:2020,2100'],
            'amount' => ['required', 'numeric', 'min:0', 'max:10000000000', 'decimal:0,2'],
            'expected_ceiling' => ['nullable', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($data, $user) {
            Office::whereKey($data['office_id'])->lockForUpdate()->firstOrFail();

            $budget = Budget::where('office_id', $data['office_id'])
                ->where('fiscal_year', $data['fiscal_year'])
                ->lockForUpdate()->first();

            $expected = isset($data['expected_ceiling'])
                ? (int) $data['expected_ceiling']
                : null;

            if ($budget?->ceiling_cents !== $expected) {
                PlanningService::fail(
                    'This office budget changed in another session. Reload before saving.'
                );
            }

            $ceiling = PlanningService::cents($data['amount']);

            $allocated = (int) Plan::where('type', 'aip')
                ->where('office_id', $data['office_id'])
                ->where('fiscal_year', $data['fiscal_year'])
                ->sum('total_cents');

            if ($ceiling < $allocated) {
                PlanningService::fail(
                    'The office budget cannot be lower than its existing PPA allocations: PHP '
                    .number_format($allocated / 100, 2).'.'
                );
            }

            $budget = Budget::updateOrCreate([
                'office_id' => $data['office_id'],
                'fiscal_year' => $data['fiscal_year'],
            ], ['ceiling_cents' => $ceiling]);

            PlanningService::audit(
                $user,
                'budget',
                $budget->id,
                'allocation_updated',
                (int) $data['office_id'],
                null,
                ['previous_cents' => $expected, 'ceiling_cents' => $ceiling]
            );
        });

        return to_route('budgets.index', ['year' => $data['fiscal_year']])
            ->with('success', 'Office budget saved.');
    }
}
