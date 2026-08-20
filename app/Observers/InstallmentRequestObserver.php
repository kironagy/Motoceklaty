<?php

namespace App\Observers;

use App\Jobs\SendWhatsappStatusNotification;
use App\Models\InstallmentRequest;

class InstallmentRequestObserver
{
    private const NOTIFIABLE_STATUSES = ['approved', 'rejected', 'paused'];

    public function updated(InstallmentRequest $model): void
    {
        if (! $model->wasChanged('status')) {
            return;
        }

        $status = (string) $model->status;

        if (! in_array($status, self::NOTIFIABLE_STATUSES, true)) {
            return;
        }

        SendWhatsappStatusNotification::dispatch(
            $model->id,
            $status,
            $this->reasonText($model->checks_report)
        );
    }

    private function reasonText(mixed $checksReport): ?string
    {
        if (is_array($checksReport)) {
            return trim(implode("\n", array_filter(array_map('strval', $checksReport))));
        }

        if (is_string($checksReport) && trim($checksReport) !== '') {
            return trim($checksReport);
        }

        return null;
    }
}
