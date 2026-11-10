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
        if (($existing?->type ?? $data['type']) !== 'aip') {
            return null;
        }

        $programId = $existing
            ? $existing->parent_ppa_id
            : ($data['parent_ppa_id'] ?? null);

        $program = Plan::whereKey($programId)
            ->where('type', 'program')
            ->lockForUpdate()
            ->first();

        if (! $program) {
            PlanningService::fail('Select a program heading for this PPA.');
        }

        PlanningAccess::view($user, $program);

        $officeId = (int) ($existing?->office_id ?? $data['office_id']);
        $year = (int) ($existing?->fiscal_year ?? $data['fiscal_year']);

        if (
            $program->office_id !== $officeId
            || $program->fiscal_year !== $year
        ) {
            PlanningService::fail(
                'The program heading must belong to the same office and fiscal year.'
            );
        }

        $data['parent_ppa_id'] = $program->id;
        $data['program_name'] = $program->title;

        return $program;
    }
}
