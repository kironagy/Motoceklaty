<?php

namespace App\Support;

use Illuminate\Database\Events\QueryExecuted;

/** Supplements model events for query-builder writes and pivot/sync operations. */
class GuideMutations
{
    public array $operations = [];
    public bool $collecting = false;
    public int $modelsRecorded = 0;

    public function collect(QueryExecuted $query): void
    {
        if (! $this->collecting || ! preg_match('/^\s*(insert(?:\s+ignore)?\s+into|replace\s+into|update|delete\s+from)\s+[`"\[]?([a-zA-Z0-9_]+)/i', $query->sql, $match)) {
            return;
        }
        $table = $match[2];
        if (in_array($table, ['guide_events', 'activity_log', 'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches'], true)) {
            return;
        }
        $verb = match (strtolower(strtok($match[1], ' '))) {
            'update' => 'تعديل', 'delete' => 'حذف', default => 'إضافة',
        };
        $this->operations[$verb.' '.$table] = $verb.' '.$table;
    }
}
