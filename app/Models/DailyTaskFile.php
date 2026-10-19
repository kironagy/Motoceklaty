<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class DailyTaskFile extends Model
{
    public const ATTACHMENT = 'attachment';
    public const PROOF = 'proof';

    public const DISK = 'public';

    protected $fillable = ['daily_task_id', 'kind', 'path', 'original_name', 'uploaded_by'];

    public function task()
    {
        return $this->belongsTo(DailyTask::class, 'daily_task_id');
    }

    public function url(): string
    {
        return Storage::disk(self::DISK)->url($this->path);
    }

    public function name(): string
    {
        return $this->original_name ?: basename($this->path);
    }

    public function isImage(): bool
    {
        return (bool) preg_match('/\.(jpe?g|png|gif|webp|bmp)$/i', $this->path);
    }

    protected static function booted(): void
    {
        static::deleted(fn (DailyTaskFile $file) => Storage::disk(self::DISK)->delete($file->path));
    }
}
