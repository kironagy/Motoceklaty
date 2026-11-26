<?php

namespace App\Console\Commands;

use App\Models\InstallmentRequest;
use Illuminate\Console\Command;

/**
 * Owner 2026-11-19: each status has its own parking account (InstallmentRequest::PARKING_ACCOUNTS):
 * متوقف 48 ساعة -> hold@, موافقة 48 ساعة -> accept@, مرفوض -> reject@ at once.
 * Owner accounts' requests move too (owner 2026-11-19: akera and Hoba included).
 */
class TransferExpiredPausedRequests extends Command
{
    protected $signature = 'requests:transfer-expired-paused {--dry : count only}';

    protected $description = 'Move paused/approved (48 h) and rejected requests to their parking accounts';

    public function handle(): int
    {
        foreach (InstallmentRequest::PARKING_ACCOUNTS as $status => [$email, $hours]) {
            $targetId = InstallmentRequest::parkingStaffId($status);
            if (! $targetId) {
                $this->warn("{$status}: no account {$email}, skipped.");

                continue;
            }

            $query = InstallmentRequest::query()
                ->where('status', $status)
                // Owner 2026-11-26: a new bot request waiting for its first check stays with its employee
                ->whereNull('bot_hold_at')
                ->where(fn ($q) => $q->whereNull('staff_id')->orWhere('staff_id', '!=', $targetId));

            if ($hours > 0) {
                $query->whereNotNull('status_updated_at')
                    ->where('status_updated_at', '<=', now()->subHours($hours))
                    // Owner 2026-11-19: moved to someone by hand = 48 h from that move, not from the status
                    ->where(fn ($q) => $q->whereNull('staff_assigned_at')->orWhere('staff_assigned_at', '<=', now()->subHours($hours)));
            }

            if ($this->option('dry')) {
                $this->info("{$status}: {$query->count()} would move to {$email}.");

                continue;
            }

            $count = 0;
            $query->chunkById(100, function ($requests) use ($targetId, &$count) {
                foreach ($requests as $request) {
                    // another run may have moved it a moment ago (two runs at once logged every move twice)
                    if ((int) $request->fresh()?->staff_id === $targetId) {
                        continue;
                    }
                    $request->update([
                        'staff_id' => $targetId,
                        // تنظيف أي تحويلات قديمة معلقة
                        'pending_staff_id' => null,
                        'transfer_requested_by' => null,
                        'transfer_requested_at' => null,
                    ]);
                    $count++;
                }
            });

            $this->info("{$status}: moved {$count} to {$email}.");
        }

        return self::SUCCESS;
    }
}
