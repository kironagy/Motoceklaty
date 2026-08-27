<?php

namespace App\Support;

/**
 * What to do when the AI call behind a customer's turn fails for a
 * transient reason (network, rate limit, unparseable JSON).
 *
 * The old answer was a third thing that is neither of these: send
 * "ثواني يا فندم، هراجعلك التفاصيل وأرد عليك." and stop. Nothing was
 * scheduled behind that sentence - the queued job counted the
 * placeholder as the turn's reply and closed itself as done - so the
 * follow-up it promised could not happen. In conversation 253 the
 * customer sat on it for eight minutes and then re-opened the
 * conversation himself.
 *
 * There are only two honest outcomes: run the turn again, or put a
 * person on it.
 */
class TransientAiFailurePolicy
{
    public const RETRY = 'retry';

    public const HANDOFF = 'handoff';

    /**
     * @param  int  $consecutiveFailures  how many times in a row this
     *                                    conversation's AI call has failed,
     *                                    counting the one just seen
     */
    public static function actionFor(int $consecutiveFailures): string
    {
        /*
         * One bad call is usually one bad call - another key, another
         * second, and the same prompt answers. Two in a row is not a
         * blip, and a third round trip only spends more of the
         * customer's patience on a service that is currently down.
         */
        return $consecutiveFailures >= 2 ? self::HANDOFF : self::RETRY;
    }
}
