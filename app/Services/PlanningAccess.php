<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;

class PlanningAccess
{
    public static function roles(User $user, array $roles): void
    {
        abort_unless(in_array($user->role, $roles, true), 403);
    }

    public static function view(User $user, Plan $plan): void
    {
        abort_if(
            $user->role === 'office'
                && $user->office_id !== $plan->office_id,
            403
        );
    }

    public static function edit(User $user, Plan $plan): void
    {
        self::roles($user, ['office', 'admin']);
        self::view($user, $plan);

        // Headings organize PPAs; they have no financial approval status.
        if ($plan->type === 'program') {
            return;
        }

        if ($plan->type === 'aip') {
            $program = $plan->parentPpa;

            abort_unless(
                $program
                    && $program->type === 'program'
                    && $program->office_id === $plan->office_id
                    && $program->fiscal_year === $plan->fiscal_year,
                409,
                'This PPA has an invalid program relationship.'
            );
        }

        abort_unless(
            in_array($plan->status, ['draft', 'returned'], true),
            409,
            'This document is locked.'
        );
    }
}
