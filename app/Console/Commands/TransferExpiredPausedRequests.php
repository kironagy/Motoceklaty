<?php

namespace App\Console\Commands;

use App\Models\InstallmentRequest;
use Illuminate\Console\Command;

/**
 * Owner 2026-11-19: each status has its own parking account (InstallmentRequest::PARKING_ACCOUNTS):
 * متوقف 48 ساعة -> hold@, موافقة 48 ساعة -> accept@, مرفوض -> reject@ at once.
 * Requests held by owner accounts (other than El Hawi, the old parking account) are left alone.
 */
class TransferExpiredPausedRequests extends Command
{
    protected $signature = 'requests:transfer-expired-paused {--dry : count only}';

    protected $description = 'Move paused/approved (48 h) and rejected requests to their parking accounts';

    public function handle(): int
    {
        $protected = InstallmentRequest::protectedOwnerIds();

        foreach (InstallmentRequest::PARKING_ACCOUNTS as $status => [$email, $hours]) {
            $targetId = InstallmentRequest::parkingStaffId($status);
            if (! $targetId) {
                $this->warn("{$status}: no account {$email}, skipped.");

                continue;
            }

            $query = InstallmentRequest::query()
                ->where('status', $status)
                ->where(fn ($q) => $q->whereNull('staff_id')->orWhere('staff_id', '!=', $targetId))
                ->where(fn ($q) => $q->whereNull('staff_id')->orWhereNotIn('staff_id', $protected));

            if ($hours > 0) {
                $query->whereNotNull('status_updated_at')
                    ->where('status_updated_at', '<=', now()->subHours($hours));
            }

            if ($this->option('dry')) {
                $this->info("{$status}: {$query->count()} would move to {$email}.");

                continue;
            }

            $count = 0;
            $query->chunkById(100, function ($requests) use ($targetId, &$count) {
                foreach ($requests as $request) {
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
