<?php

namespace App\Http\Middleware;

use App\Models\AmanToken;
use Closure;
use Illuminate\Http\Request;

/** Bearer token from the extension's sign-in (or `php artisan aman:token`) -> the Staff it belongs to. */
class AmanExtensionToken
{
    public function handle(Request $request, Closure $next)
    {
        $plain = (string) $request->bearerToken();
        $token = $plain === '' ? null : AmanToken::with('staff')->where('token_hash', hash('sha256', $plain))->first();

        if (! $token || ! $token->staff) {
            return response()->json(['message' => 'سجّل دخول تاني'], 401);
        }

        // Owner 2026-11-19: locked from كنترول الاكستنشن.
        if (! $token->staff->aman_extension_enabled) {
            return self::locked();
        }

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinutes(5))) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('aman_staff', $token->staff);
        $request->attributes->set('aman_token', $token);

        return $next($request);
    }

    public static function locked(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'message' => 'الإكستنشن مقفولة عليك من الأدمن - مش مسموحلك تستخدمها دلوقتي.',
            'locked' => true,
        ], 423);
    }
}
