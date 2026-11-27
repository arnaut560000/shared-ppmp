<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanItem;
use App\Models\PpmpOrderLine;

class PpmpReceiving
{
    public static function quantity(PlanItem $item, int $quarter): int
    {
        if (count($item->monthly_quantities ?? []) === 12) {
            return (int) array_sum(array_slice($item->monthly_quantities, ($quarter - 1) * 3, 3));
        }

        return (int) ($item->quarterly_quantities[$quarter - 1] ?? 0);
    }

    public static function summary(Plan $sheet): array
    {
        $ordersByItem = PpmpOrderLine::whereIn('plan_item_id', $sheet->items->modelKeys())
            ->withSum('receipts as accepted', 'quantity')->get()->groupBy('plan_item_id');

        return $sheet->items->mapWithKeys(function (PlanItem $item) use ($ordersByItem) {
            $orders = $ordersByItem->get($item->id, collect());
            $quarters = [];

            for ($quarter = 1; $quarter <= 4; $quarter++) {
                if (self::quantity($item, $quarter) < 1) {
                    $quarters[] = null;

                    continue;
                }

                $entries = $orders->where('quarter', $quarter);
                $quarters[] = [
                    'ordered' => (int) $entries->sum('quantity'),
                    'received' => (int) $entries->sum('accepted'),
                    'url' => route('inventory.ppmp-receiving.show', ['item' => $item, 'quarter' => $quarter]),
                ];
            }

            return [$item->catalogue_item_id => $quarters];
        })->all();
    }
}
