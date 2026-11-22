<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use App\Support\Merchants\MerchantTenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On a merchant's subdomain (aqsa.motogate-eg.com) the whole request runs on that showroom's own
 * database, and the bare host opens its dashboard. Unknown or switched-off showrooms get a 404.
 * Runs before the session starts, so sessions and logins stay per showroom too.
 */
class ResolveMerchant
{
    public function handle(Request $request, Closure $next): Response
    {
        $sub = MerchantTenancy::subdomainOf($request->getHost());

        if ($sub === null) {
            return $next($request);
        }

        $merchant = Merchant::query()->where('subdomain', $sub)->where('is_active', true)->first();

        abort_unless($merchant && file_exists(MerchantTenancy::databasePath($merchant)), 404);

        MerchantTenancy::activate($merchant);

        // A cookie of its own on its own host, so a showroom's login never touches the main site's
        config([
            'session.cookie' => config('session.cookie').'_m'.$merchant->id,
            'session.domain' => null,
        ]);

        if ($request->path() === '/') {
            return redirect('/admin');
        }

        return $next($request);
    }
}
