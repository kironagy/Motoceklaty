<?php

namespace App\Console\Commands;

use App\Models\InstallmentRequest;
use App\Models\Staff;
use Illuminate\Console\Command;

class TransferExpiredPausedRequests extends Command
{
    protected $signature = 'requests:transfer-expired-paused';

    protected $description = 'Transfer paused requests older than 48 hours to Super Admin';

    public function handle(): int
    {
        $superAdminId = 25;

        $count = 0;

        InstallmentRequest::query()
            ->where('status', 'paused')
            ->whereNotNull('status_updated_at')
            ->where('status_updated_at', '<=', now()->subHours(48))
            ->where('staff_id', '!=', $superAdminId)
            ->whereNotIn('staff_id', Staff::where('is_hitler', true)->select('id'))
            ->chunkById(100, function ($requests) use ($superAdminId, &$count) {

                foreach ($requests as $request) {

                    $request->update([
                        'staff_id' => $superAdminId,

                        // تنظيف أي تحويلات قديمة معلقة
                        'pending_staff_id' => null,
                        'transfer_requested_by' => null,
                        'transfer_requested_at' => null,
                    ]);

                    $count++;
                }
            });

        $this->info("Transferred {$count} expired paused requests to staff ID {$superAdminId}.");

        return self::SUCCESS;
    }
}
