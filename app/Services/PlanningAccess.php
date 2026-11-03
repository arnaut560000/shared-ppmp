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
            $user->role === 'office' && $user->office_id !== $plan->office_id,
            403
        );
    }

    public static function edit(User $user, Plan $plan): void
    {
        self::roles($user, ['office', 'admin']);
        self::view($user, $plan);

        if ($plan->parent_ppa_id !== null) {
            $parent = $plan->parentPpa;

            abort_unless(
                $parent
                    && $parent->type === 'aip'
                    && $parent->parent_ppa_id === null
                    && $parent->office_id === $plan->office_id
                    && $parent->fiscal_year === $plan->fiscal_year
                    && in_array($parent->status, ['draft', 'returned'], true),
                409,
                'The main PPA is locked or its relationship is invalid.'
            );
        }

        abort_unless(
            in_array($plan->status, ['draft', 'returned']),
            409,
            'This document is locked.'
        );
    }
}
