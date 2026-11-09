<?php

namespace App\Services;

use App\Models\CatalogueItem;
use App\Models\Plan;
use Illuminate\Validation\ValidationException;

class ProcurementSchedule
{
    public static function lines(array $entries, ?Plan $existing): array
    {
        if (! $entries) {
            PlanningService::fail('Add at least one catalogue item.');
        }

        $saved = $existing?->items()->get()->keyBy('catalogue_item_id') ?? collect();
        $active = CatalogueItem::where('active', true)
            ->whereIn('id', array_column($entries, 'catalogue_item_id'))->get()->keyBy('id');
        $lines = [];
        foreach ($entries as $entry) {
            $id = (int) $entry['catalogue_item_id'];
            $snapshot = $saved->get($id);
            $item = $snapshot ?? $active->get($id);
            if (! $item || ! in_array($item->expense_class, ['MOOE', 'CO'], true)) {
                PlanningService::fail('Choose an available MOOE or CO catalogue item.');
            }
            if (isset($lines[$id])) {
                PlanningService::fail('Enter all quantities for an item on one row.');
            }
            if (isset($entry['expected_price_cents']) && (int) $entry['expected_price_cents'] !== $item->price_cents) {
                throw ValidationException::withMessages(['items' => 'An item price changed. Reload the list before saving.']);
            }

            $monthly = array_key_exists('monthly_quantities', $entry);
            $field = $monthly ? 'monthly_quantities' : 'quarterly_quantities';
            validator($entry, [
                $field => ['required', 'array', 'list', 'size:'.($monthly ? 12 : 4)],
                $field.'.*' => ['required', 'integer', 'between:0,1000000'],
            ])->validate();
            $values = array_map('intval', $entry[$field]);
            $quarters = $monthly ? array_map('array_sum', array_chunk($values, 3)) : $values;
            $quantity = array_sum($quarters);
            if ($quantity === 0) {
                PlanningService::fail("Enter a positive quantity for {$item->code}.");
            }

            $lines[$id] = [
                'catalogue_item_id' => $id,
                ...$item->only(['code', 'name', 'specification', 'unit', 'expense_class', 'price_cents']),
                'monthly_quantities' => $monthly ? $values : null,
                'quarterly_quantities' => $quarters,
                'object_of_expenditure' => $snapshot ? $snapshot->object_of_expenditure : $item->category,
                'quantity' => $quantity,
                'total_cents' => $quantity * $item->price_cents,
            ];
        }

        return array_values($lines);
    }
}
