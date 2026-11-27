<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PpmpOrderLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quarter' => 'integer',
        'quantity' => 'integer',
        'register_equipment' => 'boolean',
        'ordered_on' => 'date',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(PlanItem::class, 'plan_item_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PpmpReceipt::class);
    }
}
