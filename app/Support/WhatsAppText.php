<?php

namespace App\Support;

/**
 * Converts the Markdown a model tends to write into WhatsApp's own
 * formatting. "**فرع العمرانية:**" and "[اللوكيشن](https://...)" reached
 * customers literally. Idempotent: already-WhatsApp text is unchanged.
 */
final class WhatsAppText
{
    public static function format(string $text): string
    {
        // [label](url) -> label: url
        $text = preg_replace('/\[([^\]\n]+)\]\((https?:\/\/[^)\s]+)\)/u', '$1: $2', $text);
        // **bold** / __bold__ -> *bold*
        $text = preg_replace('/\*\*(.+?)\*\*/us', '*$1*', $text);
        $text = preg_replace('/__(.+?)__/us', '_$1_', $text);
        // # headings -> *heading*
        $text = preg_replace('/^\s{0,3}#{1,6}\s+(.+?)\s*#*\s*$/mu', '*$1*', $text);
        // "* item" / "- item" bullets -> "• item"
        $text = preg_replace('/^(\s*)[*\-]\s+(?=\S)/mu', '$1• ', $text);

        return $text;
    }
}
