<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryAsset extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['received_on' => 'date', 'version' => 'integer'];

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function receivedForOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
