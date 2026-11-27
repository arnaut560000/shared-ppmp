<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    protected $guarded = ['id'];

    public function fromOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function toOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }
}
