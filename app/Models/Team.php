<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A team: one leader who hands out the daily tasks, and its members. */
class Team extends Model
{
    protected $fillable = ['name', 'leader_id', 'notes'];

    public function leader()
    {
        return $this->belongsTo(Staff::class, 'leader_id');
    }

    public function members()
    {
        return $this->belongsToMany(Staff::class, 'team_staff')->withTimestamps();
    }

    public function tasks()
    {
        return $this->hasMany(DailyTask::class);
    }

    public function reports()
    {
        return $this->hasMany(DailyTaskReport::class);
    }

    /** Ids of the teams the staff member leads. */
    public static function ledBy(?int $staffId): array
    {
        return $staffId ? static::query()->where('leader_id', $staffId)->pluck('id')->all() : [];
    }

    /** Ids of the teams the staff member is in (leader or member). */
    public static function of(?int $staffId): array
    {
        if (! $staffId) {
            return [];
        }

        return static::query()
            ->where('leader_id', $staffId)
            ->orWhereHas('members', fn ($q) => $q->whereKey($staffId))
            ->pluck('id')
            ->all();
    }
}
