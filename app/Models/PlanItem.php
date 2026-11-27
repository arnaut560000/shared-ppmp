<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
