<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AipDocument extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['submitted_snapshot'];

    protected $casts = [
        'fiscal_year' => 'integer',
        'revision' => 'integer',
        'submitted_snapshot' => 'array',
    ];

    public function isReviewLocked(): bool
    {
        return in_array($this->review_status, ['submitted', 'reviewed', 'attested'], true);
    }

    public static function ensureWritable(int $officeId, int $year, bool $procurement = false): void
    {
        $states = $procurement ? ['submitted', 'reviewed'] : ['submitted', 'reviewed', 'attested'];
        if (static::where('office_id', $officeId)->where('fiscal_year', $year)
            ->whereIn('review_status', $states)->exists()) {
            \App\Services\PlanningService::fail('This annual AIP is locked for review. Open its summary for the current decision.');
        }
    }

    public function office()
    {
        return $this->belongsTo(Office::class);
    }

    public function programs()
    {
        return $this->hasMany(Plan::class)->where('type', 'program');
    }

    public function ppas()
    {
        return $this->hasMany(Plan::class)->where('type', 'aip');
    }

    public function scopeVisibleTo($query, User $user)
    {
        return $user->role === 'office'
            ? $query->where('office_id', $user->office_id ?? 0)
            : $query;
    }
}
