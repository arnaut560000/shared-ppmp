<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;

class PpaHierarchy
{
    public static function prepare(
        User $user,
        array &$data,
        ?Plan $existing
    ): ?Plan {
        $type = $existing?->type ?? $data['type'];

        if ($type !== 'aip') {
            return null;
        }

        // Existing PPAs cannot be moved to another parent.
        $parentId = $existing
            ? $existing->parent_ppa_id
            : ($data['parent_ppa_id'] ?? null);

        $data['parent_ppa_id'] = $parentId ?: null;

        if (! $parentId) {
            return null;
        }

        // All writes to the same family lock its main PPA first.
        $parent = Plan::whereKey($parentId)
            ->lockForUpdate()
            ->first();

        if (
            ! $parent
            || $parent->type !== 'aip'
            || $parent->parent_ppa_id !== null
        ) {
            PlanningService::fail('Select a main PPA as the parent.');
        }

        PlanningAccess::edit($user, $parent);

        $officeId = $existing?->office_id ?? (int) $data['office_id'];
        $year = $existing?->fiscal_year ?? (int) $data['fiscal_year'];

        if (
            $parent->office_id !== $officeId
            || $parent->fiscal_year !== $year
        ) {
            PlanningService::fail(
                'The main PPA must belong to the same department and fiscal year.'
            );
        }

        return $parent;
    }

    public static function validateAmounts(
        array $amounts,
        ?Plan $parent,
        ?Plan $existing
    ): void {
        if ($parent) {
            foreach ($amounts as $field => $amount) {
                $allocated = (int) $parent->subPpas()
                    ->when(
                        $existing,
                        fn ($query) => $query->where('id', '!=', $existing->id)
                    )
                    ->sum($field);

                if ($allocated + $amount > $parent->$field) {
                    $label = strtoupper(str_replace('_cents', '', $field));

                    PlanningService::fail(
                        "Sub-PPA amounts exceed the main PPA's {$label} allocation."
                    );
                }
            }

            return;
        }

        // A main allocation cannot be reduced below its existing breakdown.
        if ($existing) {
            foreach ($amounts as $field => $amount) {
                $allocated = (int) $existing->subPpas()->sum($field);

                if ($amount < $allocated) {
                    $label = strtoupper(str_replace('_cents', '', $field));

                    PlanningService::fail(
                        "The main PPA's {$label} allocation cannot be below its sub-PPA total."
                    );
                }
            }
        }
    }
}
