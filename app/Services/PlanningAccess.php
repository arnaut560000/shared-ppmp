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

        abort_unless(
            in_array($plan->status, ['draft', 'returned']),
            409,
            'This document is locked.'
        );
    }
}
