<?php

namespace App\Jobs;

use App\Models\InstallmentRequest;
use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** "طلبك #id قيد المراجعة" to the customer of every new request - normal, bot or fake. Once per request. */
class SendNewRequestSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $requestId) {}

    public function handle(SmsService $sms): void
    {
        $request = InstallmentRequest::withTrashed()->find($this->requestId);

        if (! $request || SmsLog::where('installment_request_id', $request->id)->where('status', 'sent')->exists()) {
            return;
        }

        $sms->send(
            $request->applicant_phone,
            strtr(config('services.sms.new_request_message'), [':id' => $request->id]),
            $request->id,
            'new-request-'.$request->id,
        );
    }
}
