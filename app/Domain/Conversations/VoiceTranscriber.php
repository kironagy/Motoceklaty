<?php

namespace App\Domain\Conversations;

use App\Models\MessageMedia;

/**
 * DEC-08: abstracted so the transcription provider can be swapped without
 * touching the Agent Runtime or the turn scheduler.
 */
interface VoiceTranscriber
{
    public function transcribe(MessageMedia $media): TranscriptionResult;
}
