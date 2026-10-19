<?php

namespace App\Jobs;

use App\Domain\Forms\FormDocumentChecker;
use App\Models\FormLinkFile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Dynamic Links: reads one form upload after the response went out, so the customer keeps
 * filling while the OCR/AI check runs; the page polls the result.
 */
class CheckFormDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $fileId)
    {
    }

    public function handle(FormDocumentChecker $checker): void
    {
        $file = FormLinkFile::find($this->fileId);

        if (! $file || $file->check_status !== 'checking') {
            return;
        }

        try {
            $checker->check($file);
        } catch (\Throwable $e) {
            Log::warning('Form document check failed', ['file_id' => $file->id, 'error' => $e->getMessage()]);
            $file->update(['check_status' => 'failed']);
        }
    }
}
