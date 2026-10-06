<?php

namespace App\Agent\Tools;

/**
 * Rebuild ARCH-003 (tool contract v2, docs/rebuild/tool-contract-v2.md):
 * a READ tool answers from the DB. It writes nothing, calls no model
 * (unless it declares one in AI_CALLING_READ_TOOLS: vision, web specs) and
 * returns facts and codes - no wording instructions. permission() = READ.
 */
interface ReadTool extends Tool
{
}
