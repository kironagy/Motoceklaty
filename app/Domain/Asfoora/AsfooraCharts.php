<?php

namespace App\Domain\Asfoora;

/**
 * Owner 2026-11-26: «عصفورة» draws its numbers. The AI writes a ```chart block with JSON
 * ({"type":"bar"|"line","title":..,"labels":[..],"values":[..],"previous":[..]?}); it is drawn here,
 * on the server, as plain HTML/SVG - no chart library, nothing for the AI to inject.
 */
class AsfooraCharts
{
    /** Replaces the chart blocks with markers; returns [markdown, list of chart HTML]. */
    public static function extract(string $markdown): array
    {
        $charts = [];
        $markdown = preg_replace_callback('/```chart\s*\n(.*?)\n```/s', function ($m) use (&$charts) {
            $spec = json_decode(trim($m[1]), true);
            if (! is_array($spec)) {
                return '';
            }
            $charts[] = self::draw($spec);

            return "\n\nMGASFCHART".(count($charts) - 1)."\n\n";
        }, $markdown);

        return [$markdown, $charts];
    }

    public static function put(string $html, array $charts): string
    {
        return preg_replace_callback('/(?:<p>)?MGASFCHART(\d+)(?:<\/p>)?/', fn ($m) => $charts[(int) $m[1]] ?? '', $html);
    }

    private static function draw(array $spec): string
    {
        $labels = array_slice(array_map(fn ($l) => mb_substr((string) $l, 0, 40), (array) ($spec['labels'] ?? [])), 0, 31);
        $values = array_slice(array_map(fn ($v) => (float) $v, (array) ($spec['values'] ?? [])), 0, count($labels));
        $previous = array_slice(array_map(fn ($v) => (float) $v, (array) ($spec['previous'] ?? [])), 0, count($labels));
        if ($labels === [] || $values === []) {
            return '';
        }
        $title = e(mb_substr((string) ($spec['title'] ?? ''), 0, 120));
        $legend = $previous !== []
            ? '<div class="mg-asf-legend"><span><i class="now"></i>'.e((string) ($spec['label'] ?? 'الفترة دي')).'</span><span><i class="prev"></i>'.e((string) ($spec['previous_label'] ?? 'اللي قبلها')).'</span></div>'
            : '';

        $body = ($spec['type'] ?? 'bar') === 'line' ? self::line($labels, $values, $previous) : self::bars($labels, $values, $previous);

        return '<figure class="mg-asf-chart">'.($title !== '' ? '<figcaption>'.$title.'</figcaption>' : '').$legend.$body.'</figure>';
    }

    private static function bars(array $labels, array $values, array $previous): string
    {
        $max = max(1, ...$values, ...($previous ?: [0]));
        $rows = '';
        foreach ($labels as $i => $label) {
            $v = $values[$i] ?? 0;
            $p = $previous[$i] ?? null;
            $rows .= '<div class="mg-asf-bar" style="--i:'.$i.'"><span class="lbl">'.e($label).'</span><span class="track">'
                .'<b class="now" style="width:'.round($v * 100 / $max, 1).'%"></b>'
                .($p !== null ? '<b class="prev" style="width:'.round($p * 100 / $max, 1).'%"></b>' : '')
                .'</span><span class="val">'.self::num($v).($p !== null ? '<small>'.self::num($p).'</small>' : '').'</span></div>';
        }

        return '<div class="mg-asf-bars">'.$rows.'</div>';
    }

    private static function line(array $labels, array $values, array $previous): string
    {
        $w = 640;
        $h = 200;
        $pad = 26;
        $n = max(2, count($labels));
        $max = max(1, ...$values, ...($previous ?: [0]));
        $x = fn ($i) => round($pad + $i * ($w - 2 * $pad) / ($n - 1), 1);
        $y = fn ($v) => round($h - $pad - $v * ($h - 2 * $pad) / $max, 1);
        $path = fn ($series) => implode(' ', array_map(fn ($v, $i) => $x($i).','.$y($v), $series, array_keys($series)));

        $dots = '';
        foreach ($values as $i => $v) {
            $dots .= '<circle cx="'.$x($i).'" cy="'.$y($v).'" r="3.5"><title>'.e($labels[$i] ?? '').': '.self::num($v).'</title></circle>';
        }
        $area = $x(0).','.($h - $pad).' '.$path($values).' '.$x(count($values) - 1).','.($h - $pad);

        return '<svg class="mg-asf-line" viewBox="0 0 '.$w.' '.$h.'" preserveAspectRatio="none" role="img">'
            .'<polygon class="area" points="'.$area.'"/>'
            .($previous !== [] ? '<polyline class="prev" points="'.$path($previous).'"/>' : '')
            .'<polyline class="now" points="'.$path($values).'"/>'.$dots.'</svg>'
            .'<div class="mg-asf-axis"><span>'.e($labels[0]).'</span><span>'.self::num(max($values)).' أعلى قيمة</span><span>'.e(end($labels)).'</span></div>';
    }

    private static function num(float $v): string
    {
        return fmod($v, 1.0) === 0.0 ? number_format($v) : number_format($v, 1);
    }
}
