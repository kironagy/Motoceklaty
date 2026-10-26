<?php

namespace App\Http\Middleware;

use App\Support\GuideMutations;
use App\Support\GuideRecorder;
use Closure;
use Illuminate\Http\Request;

class RecordGuideActions
{
    public function handle(Request $request, Closure $next)
    {
        $mutations = app(GuideMutations::class);
        $mutations->operations = [];
        $mutations->modelsRecorded = 0;
        $mutations->collecting = true;
        $response = null;
        try {
            return $response = $next($request);
        } finally {
            $mutations->collecting = false;
            // Log writes even when a later step fails; the persisted writes may still exist.
            $actions = [];
            $page = null;
            foreach ((array) $request->input('components', []) as $component) {
                if (!is_array($component)) continue;
                $snapshot = is_string($component['snapshot'] ?? null) ? json_decode($component['snapshot'], true) : [];
                $page ??= mb_substr((string) ($snapshot['memo']['path'] ?? ''), 0, 500);
                foreach ((array) ($component['calls'] ?? []) as $call) {
                    if (is_string($call['method'] ?? null) && preg_match('/^(callMounted(?:Table)?Action|save|create|delete|submit|download|export|clockIn|clockOut)$/', $call['method'])) {
                        $actions[] = mb_substr($call['method'], 0, 100);
                    }
                }
            }
            if ($mutations->modelsRecorded === 0 && ($mutations->operations || ($actions && app(GuideRecorder::class)->actor()))) {
                app(GuideRecorder::class)->write('action', 'إجراء على السيستم', 'نفّذ إجراء على السيستم', context: [
                    'route' => $request->route()?->getName(), 'method' => $request->method(),
                    'actions' => $actions, 'operations' => array_values($mutations->operations),
                    'page' => $page, 'response_status' => $response?->getStatusCode(),
                ]);
            }
        }
    }
}
