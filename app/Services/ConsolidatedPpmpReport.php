<?php

namespace App\Services;

use App\Models\Office;
use App\Models\Plan;
use App\Models\User;

class ConsolidatedPpmpReport
{
    public function build(
        User $user,
        int $year,
        ?int $officeId = null,
        ?string $fund = null
    ): array {
        PlanningAccess::roles($user, [
            'admin',
            'office',
            'budget',
            'mayor',
            'procurement',
            'gso',
            'inventory',
        ]);
        if ($user->role === 'office') {
            abort_unless($user->office_id, 403);
            abort_if($officeId && $officeId !== $user->office_id, 403);

            $officeId = $user->office_id;
        }

        $offices = Office::when(
            $officeId,
            fn ($q) => $q->whereKey($officeId)
        )
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $plans = Plan::visibleTo($user)
            ->where('type', 'ppmp')
            ->where('status', 'approved')
            ->where('fiscal_year', $year)
            ->when(
                $officeId,
                fn ($q) => $q->where('office_id', $officeId)
            )
            ->when(
                $fund,
                fn ($q) => $q->where('fund_source', $fund)
            )
            ->with('items')
            ->get();
        $rows = [];
        $totals = array_fill_keys($offices->pluck('id')->all(), 0);

        foreach ($plans as $plan) {
            foreach ($plan->items as $item) {
                // Different saved prices/specifications remain separate rows.
                $identity = [
                    $item->catalogue_item_id,
                    $item->code,
                    $item->name,
                    $item->specification,
                    $item->unit,
                    $item->price_cents,
                    $item->expense_class,
                    $item->object_of_expenditure,
                ];

                $key = hash(
                    'sha256',
                    json_encode($identity, JSON_THROW_ON_ERROR)
                );

                $rows[$key] ??= [
                    'key' => $key,
                    'category' => $item->object_of_expenditure
                        ?? 'Unclassified legacy item',
                    'code' => $item->code,
                    'name' => $item->name,
                    'specification' => $item->specification,
                    'unit' => $item->unit,
                    'price_cents' => $item->price_cents,
                    'offices' => [],
                ];

                $rows[$key]['offices'][$plan->office_id] ??= [
                    'quantities' => [0, 0, 0, 0],
                    'amounts' => [0, 0, 0, 0],
                    'quantity' => 0,
                    'amount' => 0,
                ];

                foreach ($item->quarterly_quantities as $q => $quantity) {
                    $amount = $quantity * $item->price_cents;

                    $rows[$key]['offices'][$plan->office_id]['quantities'][$q]
                        += $quantity;

                    $rows[$key]['offices'][$plan->office_id]['amounts'][$q]
                        += $amount;

                    $rows[$key]['offices'][$plan->office_id]['quantity']
                        += $quantity;

                    $rows[$key]['offices'][$plan->office_id]['amount']
                        += $amount;

                    $totals[$plan->office_id] += $amount;
                }
            }
        }

        $rows = array_values($rows);

        usort(
            $rows,
            fn ($a, $b) => [
                $a['category'],
                $a['name'],
                $a['price_cents'],
                $a['key'],
            ] <=> [
                $b['category'],
                $b['name'],
                $b['price_cents'],
                $b['key'],
            ]
        );

        return [
            'offices' => $offices,
            'rows' => $rows,
            'office_totals' => $totals,
            'total_cents' => array_sum($totals),
            'plan_count' => $plans->count(),
        ];
    }
}
