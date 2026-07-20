<?php

namespace App\Services;

use Illuminate\Support\Collection;

class AiReplyBuilder
{
    public function fromMemories(
        Collection $memories,
        array $variables = [],
        ?string $fallback = null
    ): string {
        $templates = [];

        $parser = app(AiMemoryParser::class);

        foreach ($memories as $memory) {
            foreach ($parser->templateReplies($memory) as $reply) {
                $templates[] = $reply;
            }
        }

        if (! count($templates)) {
            return $this->render($fallback ?? '', $variables);
        }

        $template = $templates[array_rand($templates)];

        return $this->render($template, $variables);
    }

    public function render(string $template, array $variables = []): string
    {
        foreach ($variables as $key => $value) {
            if (is_array($value)) {
                $value = implode('، ', array_filter($value));
            }

            $template = str_replace('{' . $key . '}', (string) $value, $template);
        }

        $template = preg_replace('/\{[a-zA-Z0-9_]+\}/', '', $template);
        $template = preg_replace("/[ \t]+/u", ' ', $template);
        $template = preg_replace("/\n{3,}/u", "\n\n", $template);

        return trim($template);
    }
}