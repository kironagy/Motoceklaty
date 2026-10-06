<?php

namespace App\Jobs;

use App\Domain\Applications\LegacyRequestProjector;
use App\Models\InstallmentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * APP-006: the AI address split (AddressSplitter) ran inside the submit
 * transaction - up to 25 s the customer waited for "اتقدم طلبك", and the
 * transaction stayed open. The request is created with the deterministic
 * AddressParser split; this job refines the address columns afterwards.
 * A column staff already changed by hand is never touched.
 */
class SplitRequestAddresses implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $installmentRequestId)
    {
    }

    public function handle(LegacyRequestProjector $projector): void
    {
        $request = InstallmentRequest::find($this->installmentRequestId);
        $application = $request?->application;

        if (! $application) {
            return;
        }

        $quick = $projector->addressColumns($application, aiSplit: false);
        $split = $projector->addressColumns($application, aiSplit: true);
        $update = [];

        foreach (array_unique(array_merge(array_keys($quick), array_keys($split))) as $column) {
            $written = $quick[$column] ?? null;
            $better = $split[$column] ?? null;

            // still what submit wrote = staff did not edit it
            if ((string) $request->getAttribute($column) === (string) $written && (string) $better !== (string) $written) {
                $update[$column] = $better;
            }
        }

        if ($update !== []) {
            $request->forceFill($update)->saveQuietly();
        }
    }
}
