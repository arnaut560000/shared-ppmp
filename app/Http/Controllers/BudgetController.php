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
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        PlanningAccess::roles($user, [
            'admin',
            'office',
            'budget',
            'mayor',
            'procurement',
            'gso',
            'inventory',
        ]);

        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'between:2020,2100'],
        ]);

        $year = (int) ($validated['year'] ?? now()->year);
        $canManage = in_array($user->role, ['admin', 'budget'], true);
        $needsOffice = $user->role === 'office'
            && $user->office_id === null;

        $officeQuery = Office::query()->orderBy('name');

        if ($user->role === 'office') {
            $officeQuery->whereKey($user->office_id ?? 0);
        }

        $offices = $officeQuery->get(['id', 'code', 'name']);
        $officeIds = $offices->pluck('id');

        $budgets = Budget::query()
            ->where('fiscal_year', $year)
            ->whereIn('office_id', $officeIds)
            ->get()
            ->keyBy('office_id');

        $reservations = Plan::query()
            ->where('fiscal_year', $year)
            ->whereIn('office_id', $officeIds)
            ->where('type', 'aip')
            ->whereIn('status', ['submitted', 'approved'])
            ->select('office_id')
            ->selectRaw('SUM(total_cents) AS reserved_cents')
            ->groupBy('office_id')
            ->get()
            ->keyBy('office_id');

        $rows = $offices->map(function (Office $office) use (
            $budgets,
            $reservations
        ) {
            $budget = $budgets->get($office->id);
            $ceiling = (int) ($budget?->ceiling_cents ?? 0);
            $reserved = (int) (
                $reservations->get($office->id)?->reserved_cents ?? 0
            );

            return [
                'office_id' => $office->id,
                'code' => $office->code,
                'name' => $office->name,
                'allocated' => $budget !== null,
                'ceiling_cents' => $ceiling,
                'reserved_cents' => $reserved,
                'available_cents' => $ceiling - $reserved,
            ];
        });

        return Inertia::render('budgets', [
            'year' => $year,
            'canManage' => $canManage,
            'needsOffice' => $needsOffice,
            'rows' => $rows,
            'message' => $request->session()->get('success'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        PlanningAccess::roles($request->user(), ['admin', 'budget']);

        $data = $request->validate([
            'office_id' => ['required', 'integer', 'exists:offices,id'],
            'fiscal_year' => ['required', 'integer', 'between:2020,2100'],
            'ceiling' => [
                'required',
                'numeric',
                'min:0',
                'max:10000000000',
                'decimal:0,2',
            ],
            'comment' => ['required', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $data) {
            // Serialize first-time allocations for the same office.
            Office::query()
                ->whereKey($data['office_id'])
                ->lockForUpdate()
                ->firstOrFail();

            // Use the same budget lock as AIP submission.
            $budget = Budget::query()
                ->where('office_id', $data['office_id'])
                ->where('fiscal_year', $data['fiscal_year'])
                ->lockForUpdate()
                ->first();

            $reserved = (int) Plan::query()
                ->where('office_id', $data['office_id'])
                ->where('fiscal_year', $data['fiscal_year'])
                ->where('type', 'aip')
                ->whereIn('status', ['submitted', 'approved'])
                ->sum('total_cents');

            $ceiling = PlanningService::cents($data['ceiling']);

            if ($ceiling < $reserved) {
                throw ValidationException::withMessages([
                    'ceiling' => 'The ceiling cannot be lower than the '
                        .'submitted and approved AIP allocations: PHP '
                        .number_format($reserved / 100, 2).'.',
                ]);
            }

            $before = $budget?->ceiling_cents;

            if ($budget) {
                $budget->update(['ceiling_cents' => $ceiling]);
            } else {
                $budget = Budget::create([
                    'office_id' => $data['office_id'],
                    'fiscal_year' => $data['fiscal_year'],
                    'ceiling_cents' => $ceiling,
                ]);
            }

            PlanningService::audit(
                $request->user(),
                'budget',
                $budget->id,
                'allocation adjusted',
                $budget->office_id,
                $data['comment'],
                [
                    'fiscal_year' => $budget->fiscal_year,
                    'before_cents' => $before,
                    'after_cents' => $ceiling,
                ],
            );
        }, 3);

        return to_route('budgets.index', [
            'year' => $data['fiscal_year'],
        ])->with('success', 'Office budget saved successfully.');
    }
}