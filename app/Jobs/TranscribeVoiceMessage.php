<?php

namespace App\Jobs;

use App\Domain\Conversations\VoiceTranscriber;
use App\Models\WhatsappMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TranscribeVoiceMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $messageId)
    {
    }

    public function handle(VoiceTranscriber $transcriber): void
    {
        $message = WhatsappMessage::with('media')->find($this->messageId);
        $media = $message?->media->first();

        if (! $message || ! $media) {
            return;
        }

        $result = $transcriber->transcribe($media);

        $message->update([
            'transcription_status' => $result->status,
            'transcript' => $result->transcript,
        ]);
    }
}
