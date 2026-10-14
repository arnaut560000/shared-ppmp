<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'total_cents' => 'integer',
        'ps_cents' => 'integer',
        'mooe_cents' => 'integer',
        'fe_cents' => 'integer',
        'co_cents' => 'integer',
        'fiscal_year' => 'integer',
        'version' => 'integer',
    ];

    public function office()
    {
        return $this->belongsTo(Office::class);
    }

    public function items()
    {
        return $this->hasMany(PlanItem::class);
    }

    public function parent()
    {
        return $this->belongsTo(Plan::class, 'parent_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeVisibleTo($query, User $user)
    {
        return $user->role === 'office'
            ? $query->where('office_id', $user->office_id)
            : $query;
    }
}
