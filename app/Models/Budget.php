<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'ceiling_cents' => 'integer',
        'fiscal_year' => 'integer',
    ];

    public function office()
    {
        return $this->belongsTo(Office::class);
    }
}
