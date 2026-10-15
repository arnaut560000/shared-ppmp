<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Plan;
use App\Services\PlanningAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
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
        $needsOffice = $user->role === 'office'
            && $user->office_id === null;

        $plans = Plan::query()->where('fiscal_year', $year);
        $budgets = Budget::query()->where('fiscal_year', $year);

        if ($user->role === 'office') {
            if ($needsOffice) {
                $plans->whereRaw('1 = 0');
                $budgets->whereRaw('1 = 0');
            } else {
                $plans->where('office_id', $user->office_id);
                $budgets->where('office_id', $user->office_id);
            }
        }

        $ceiling = (int) (clone $budgets)->sum('ceiling_cents');

        $reserved = (int) (clone $plans)
            ->where('type', 'aip')
            ->whereIn('status', ['submitted', 'approved'])
            ->sum('total_cents');

        $counts = [];

        foreach (['draft', 'submitted', 'approved', 'returned'] as $status) {
            $counts[$status] = (clone $plans)
                ->where('status', $status)
                ->count();
        }

        $recent = (clone $plans)
            ->with('office:id,code,name')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'reference' => $plan->reference,
                'title' => $plan->title,
                'type' => $plan->type,
                'status' => $plan->status,
                'office' => $plan->office?->code ?? 'Unassigned',
                'total_cents' => $plan->total_cents,
            ]);

        return Inertia::render('dashboard', [
            'year' => $year,
            'account' => [
                'name' => $user->name,
                'role' => $user->role,
                'office' => $user->office?->name,
                'needsOffice' => $needsOffice,
            ],
            'stats' => [
                'ceiling' => $ceiling,
                'reserved' => $reserved,
                'available' => $ceiling - $reserved,
                'counts' => $counts,
            ],
            'recent' => $recent,
        ]);
    }
}