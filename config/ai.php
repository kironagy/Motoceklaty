<?php

return [

    /*
     * How many consecutive low-confidence / needs_clarification turns are
     * allowed on the same stuck topic before the conversation is handed
     * off to a human agent instead of asking another clarification
     * question. See ClarificationService.
     */
    'max_clarification_attempts' => (int) env('AI_MAX_CLARIFICATION_ATTEMPTS', 3),

];
