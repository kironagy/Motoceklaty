<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a customer's turn could not be generated for a transient
 * reason and should simply be run again.
 *
 * It is deliberately an exception rather than a reply: the WhatsApp job
 * worker already treats a thrown turn as "put this job back to pending
 * and process it again" (ProcessWhatsappMessageJobs), so throwing is
 * what actually produces the retry. Returning any text here instead -
 * which is what "ثواني يا فندم، هراجعلك التفاصيل وأرد عليك." did - marks
 * the job done and guarantees the promised follow-up never comes.
 */
class TransientAiFailure extends RuntimeException
{
}
