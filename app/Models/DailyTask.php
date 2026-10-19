<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One task of the day a team leader gives a member: todo -> in progress -> done, with files both ways. */
class DailyTask extends Model
{
    public const TODO = 'todo';
    public const DOING = 'doing';
    public const DONE = 'done';

    public const STATUSES = [
        self::TODO => 'مطلوب',
        self::DOING => 'جاري التنفيذ',
        self::DONE => 'تم التنفيذ',
    ];

    public const COLORS = [
        self::TODO => 'warning',
        self::DOING => 'info',
        self::DONE => 'success',
    ];

    protected $fillable = [
        'team_id', 'assigned_to', 'created_by', 'title', 'description', 'task_date',
        'status', 'started_at', 'done_note', 'done_at', 'report_id',
    ];

    protected $casts = [
        'task_date' => 'date',
        'started_at' => 'datetime',
        'done_at' => 'datetime',
    ];

    /** Still open on a day after its own. */
    public function isLate(): bool
    {
        return $this->status !== self::DONE && $this->task_date?->lt(today());
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function assignee()
    {
        return $this->belongsTo(Staff::class, 'assigned_to');
    }

    public function creator()
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    public function files()
    {
        return $this->hasMany(DailyTaskFile::class);
    }

    public function attachments()
    {
        return $this->files()->where('kind', DailyTaskFile::ATTACHMENT);
    }

    public function proofs()
    {
        return $this->files()->where('kind', DailyTaskFile::PROOF);
    }

    protected static function booted(): void
    {
        // The rows cascade in the database; the files on disk go with them.
        static::deleting(fn (DailyTask $task) => $task->files()->get()->each->delete());
    }

    public function report()
    {
        return $this->belongsTo(DailyTaskReport::class, 'report_id');
    }
}
