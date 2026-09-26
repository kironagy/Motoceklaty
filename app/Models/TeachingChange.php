<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeachingChange extends Model
{
    protected $fillable = ['teaching_session_id', 'kind', 'target_type', 'target_id', 'summary', 'before', 'after', 'status', 'error', 'applied_at', 'applied_by'];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'applied_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(TeachingSession::class, 'teaching_session_id');
    }

    /** @return array{0: mixed, 1: mixed} what the target looks like before and after this change */
    public function diff(): array
    {
        if ($this->status === 'applied' || $this->status === 'reverted') {
            return [$this->before, $this->after];
        }

        $before = match ($this->kind) {
            'data_update' => rescue(fn () => \App\Domain\Teaching\EditableEntities::model($this->target_type)::find($this->target_id)
                ?->only(array_keys((array) $this->after)), null, false),
            'setting' => ['value' => config("agent.{$this->target_id}")],
            'lesson' => isset($this->after['lesson_id']) ? BotLesson::find($this->after['lesson_id'])?->only(\App\Domain\Teaching\ChangeApplier::LESSON_FIELDS) : null,
            default => null,
        };

        return [$before, $this->after];
    }

    public function needsApproval(): bool
    {
        return $this->kind !== 'lesson';
    }
}
