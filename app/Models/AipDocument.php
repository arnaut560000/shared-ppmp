<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AipDocument extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['fiscal_year' => 'integer'];

    public function office()
    {
        return $this->belongsTo(Office::class);
    }

    public function ppas()
    {
        return $this->hasMany(Plan::class)->where('type', 'aip');
    }

    public function mainPpas()
    {
        return $this->ppas()->whereNull('parent_ppa_id');
    }

    public function scopeVisibleTo($query, User $user)
    {
        return $user->role === 'office'
            ? $query->where('office_id', $user->office_id ?? 0)
            : $query;
    }
}
