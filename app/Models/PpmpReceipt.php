<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PpmpReceipt extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['received_on' => 'date', 'quantity' => 'integer'];

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(InventoryAsset::class, 'ppmp_receipt_assets');
    }
}
