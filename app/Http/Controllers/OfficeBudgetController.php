<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Office;
use App\Services\OfficeBudgetService;
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
            $summary = OfficeBudgetService::summary($office->id, $year);

            return [
                'id' => $office->id,
                'code' => $office->code,
                'name' => $office->name,
                ...$summary,
                'allocated_cents' => $summary['spent_cents'],
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

            $spent = OfficeBudgetService::summary((int) $data['office_id'], (int) $data['fiscal_year'])['spent_cents'];
            if ($ceiling < $spent) {
                PlanningService::fail('The ceiling cannot be lower than current office spending of PHP '
                    .number_format($spent / 100, 2).'.');
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
        }, 3);

        return to_route('budgets.index', ['year' => $data['fiscal_year']])
            ->with('success', 'Office budget saved.');
    }
}
