<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'monthly_quantities' => 'array',
        'quarterly_quantities' => 'array',
        'price_cents' => 'integer',
        'total_cents' => 'integer',
        'quantity' => 'integer',
    ];
}
