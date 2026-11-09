<?php

namespace App\Support;

/**
 * Owner 2026-11-09: the installment answer reached the customer as one run-on
 * paragraph ("F250: سنة مقدم 18,000 وقسط 6,050، سنة ونص 4,383، سنتين ...").
 * A line that carries two or more duration/amount pairs is laid out: the
 * model name as a bold heading, every duration on its own bullet line, the
 * admin fee and the closing question on lines of their own. Lines with fewer
 * than two pairs are left exactly as written.
 */
final class InstallmentLayout
{
    private const DURATION = '(?:سن[ةه]\s+ونص|سنتين|(?:3|٣|تلات|تلت)\s*سنين|(?:\d+|[٠-٩]+)\s*(?:شهر|شهور|أشهر|اشهر)|سن[ةه])';

    private const AMOUNT = '[\d٠-٩][\d٠-٩,٬.]*';

    public static function format(string $text): string
    {
        $lines = preg_split('/\n/u', $text);

        foreach ($lines as $i => $line) {
            if (self::pairs($line) < 2) {
                continue;
            }

            $d = self::DURATION;

            // "F250: سنة ..." / "V250 MAX: سنة ..." -> heading line
            $line = preg_replace('/(?:^|(?<=\s))([A-Za-z][A-Za-z0-9 .\-]{0,25}?)\s*:\s*(?='.$d.'(?!\p{L}))/u', "\n\n*$1*\n• ", $line);
            // "، سنة ونص 4,383" / ", و٣ سنين 2,717" -> one bullet per duration
            $line = preg_replace('/\s*[،,]\s*(?:و\s*)?(?='.$d.'(?!\p{L}))/u', "\n• ", $line);
            // the admin fee and what follows on their own lines
            $line = preg_replace('/\s*[.،]?\s+(?=(?:وال|ال)?مصاريف)/u', "\n", $line);
            $line = preg_replace('/(?<=[^\d٠-٩])\.\s+(?=\S)/u', ".\n", $line);
            $line = preg_replace('/(?<=[\d٠-٩])\.\s+(?=\S)/u', "\n", $line);
            // a dangling "•" when a heading had no text after it, and a trailing comma
            $line = preg_replace('/[ \t]*[،,][ \t]*(?=\n)/u', '', $line);

            // a blank line between the intro and each laid-out model
            $lines[$i] = ($i > 0 ? "\n" : '').trim($line);
        }

        $out = implode("\n", $lines);
        $out = preg_replace("/\n{3,}/u", "\n\n", $out);

        return trim((string) $out);
    }

    private static function pairs(string $line): int
    {
        return preg_match_all('/'.self::DURATION.'(?!\p{L})[^\n\d٠-٩]{0,25}'.self::AMOUNT.'/u', $line);
    }
}
