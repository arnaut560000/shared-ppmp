<?php

namespace App\Services;

use App\Models\Budget;
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

    public static function validateAmounts(
        array $amounts,
        ?Plan $program,
        ?Plan $existing
    ): void {
        if (! $program) {
            PlanningService::fail('This PPA needs a program heading.');
        }

        $budget = Budget::where('office_id', $program->office_id)
            ->where('fiscal_year', $program->fiscal_year)
            ->lockForUpdate()
            ->first();

        if (! $budget) {
            PlanningService::fail(
                'The Budget Office must assign an office budget for this fiscal year first.'
            );
        }

        // All PPA allocations count, including drafts and returned PPAs.
        // Program headings and PPMPs are not counted here.
        $allocated = (int) Plan::where('type', 'aip')
            ->where('office_id', $program->office_id)
            ->where('fiscal_year', $program->fiscal_year)
            ->when($existing, fn ($query) => $query->where('id', '!=', $existing->id))
            ->sum('total_cents');

        $available = $budget->ceiling_cents - $allocated;

        if (array_sum($amounts) > $available) {
            PlanningService::fail(
                'This PPA exceeds the remaining office budget. '
                .'Available for this PPA: PHP '
                .number_format(max(0, $available) / 100, 2).'.'
            );
        }

        // Existing procurement requirements must still fit after a PPA edit.
        if ($existing) {
            foreach (['mooe_cents', 'co_cents'] as $field) {
                $required = (int) Plan::where('type', 'ppmp')
                    ->where('parent_id', $existing->id)
                    ->sum($field);

                if ($amounts[$field] < $required) {
                    PlanningService::fail(
                        'The PPA allocation cannot be reduced below its existing PPMP '
                        .strtoupper(str_replace('_cents', '', $field))
                        .' requirements.'
                    );
                }
            }
        }
    }

    public static function validateProcurement(
        array $amounts,
        int $ppaId,
        ?Plan $existing
    ): void {
        $ppa = Plan::whereKey($ppaId)
            ->where('type', 'aip')
            ->lockForUpdate()
            ->firstOrFail();

        foreach (['mooe_cents', 'co_cents'] as $field) {
            $allocated = (int) Plan::where('type', 'ppmp')
                ->where('parent_id', $ppa->id)
                ->when($existing, fn ($query) => $query->where('id', '!=', $existing->id))
                ->sum($field);

            if ($allocated + $amounts[$field] > $ppa->$field) {
                PlanningService::fail(
                    'PPMP requirements exceed this PPA’s '
                    .strtoupper(str_replace('_cents', '', $field))
                    .' allocation.'
                );
            }
        }
    }
}
