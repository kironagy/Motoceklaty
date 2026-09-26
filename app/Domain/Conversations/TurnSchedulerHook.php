<?php

namespace App\Domain\Conversations;

use App\Models\WhatsappMessage;

/**
 * Called by IngestionService after a message is committed. T05 binds the
 * real TurnScheduler here; until then this stays a no-op so T04 doesn't
 * depend on T05's tables.
 */
interface TurnSchedulerHook
{
    public function onMessageIngested(WhatsappMessage $message): void;

    public function staffTookOver(\App\Models\WhatsappConversation $conversation): void;
}
