<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnnualPlan extends Model
{
    protected $guarded = ['id'];

    public function plans()
    {
        return $this->hasMany(Plan::class);
    }
}
