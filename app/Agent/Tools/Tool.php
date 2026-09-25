<?php

namespace App\Agent\Tools;

interface Tool
{
    /** Stable snake_case name the AI calls this tool by. */
    public function name(): string;

    /** AI-facing usage guidance: what it's for, when to use/not use it. */
    public function description(): string;

    /** JSON schema for the arguments (see JsonSchemaValidator for supported keywords). */
    public function inputSchema(): array;

    /** READ | WRITE | DESTRUCTIVE (plan principle 8). */
    public function permission(): string;

    public function execute(array $args, ToolContext $ctx): ToolResult;
}
