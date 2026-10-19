<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A team's day, sent by its leader to the owner: every task of that day with its proofs. */
class DailyTaskReport extends Model
{
    protected $fillable = ['team_id', 'sent_by', 'report_date', 'tasks_total', 'tasks_done', 'notes'];

    protected $casts = [
        'report_date' => 'date',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function sender()
    {
        return $this->belongsTo(Staff::class, 'sent_by');
    }

    public function tasks()
    {
        return $this->hasMany(DailyTask::class, 'report_id');
    }
}
