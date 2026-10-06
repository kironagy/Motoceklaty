<?php

namespace App\Agent\Tools;

/**
 * Rebuild ARCH-003 (tool contract v2): a WRITE tool is the only way state
 * changes - explicit, idempotent for the same semantic request, and it
 * returns {ok, data (delta + next_step), error{code, detail}}.
 * permission() = WRITE or DESTRUCTIVE.
 */
interface WriteTool extends Tool
{
}
