<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogueItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'active' => 'boolean',
        'price_cents' => 'integer',
    ];
}
