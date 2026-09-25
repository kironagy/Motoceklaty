<?php

namespace App\Listeners;

use App\Models\Staff;
use App\Models\StaffLoginLog;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

class LogStaffLogin
{
    public function __construct(
        protected Request $request
    ) {}

    public function handle(Login $event): void
    {
        /*
        |--------------------------------------------------------------------------
        | Filament عندك يستخدم Staff كـ Auth Model
        |--------------------------------------------------------------------------
        */

        $staff = $event->user;

        if (! $staff instanceof Staff) {
            return;
        }

        $userAgent = $this->request->userAgent() ?? '';

        /*
        |--------------------------------------------------------------------------
        | Platform
        |--------------------------------------------------------------------------
        */

        if (str_contains($userAgent, 'Windows')) {
            $platform = 'Windows';
        } elseif (str_contains($userAgent, 'Macintosh')) {
            $platform = 'macOS';
        } elseif (str_contains($userAgent, 'Android')) {
            $platform = 'Android';
        } elseif (
            str_contains($userAgent, 'iPhone') ||
            str_contains($userAgent, 'iPad')
        ) {
            $platform = 'iOS';
        } elseif (str_contains($userAgent, 'Linux')) {
            $platform = 'Linux';
        } else {
            $platform = 'غير معروف';
        }

        /*
        |--------------------------------------------------------------------------
        | Device Type
        |--------------------------------------------------------------------------
        */

        if (
            str_contains($userAgent, 'Mobile') ||
            str_contains($userAgent, 'Android') ||
            str_contains($userAgent, 'iPhone')
        ) {
            $deviceType = 'موبايل';
        } elseif (
            str_contains($userAgent, 'iPad') ||
            str_contains($userAgent, 'Tablet')
        ) {
            $deviceType = 'تابلت';
        } else {
            $deviceType = 'كمبيوتر';
        }

        /*
        |--------------------------------------------------------------------------
        | Browser
        |--------------------------------------------------------------------------
        */

        if (str_contains($userAgent, 'Edg/')) {
            $browser = 'Microsoft Edge';
        } elseif (str_contains($userAgent, 'OPR/')) {
            $browser = 'Opera';
        } elseif (str_contains($userAgent, 'Chrome/')) {
            $browser = 'Google Chrome';
        } elseif (str_contains($userAgent, 'Firefox/')) {
            $browser = 'Mozilla Firefox';
        } elseif (str_contains($userAgent, 'Safari/')) {
            $browser = 'Safari';
        } else {
            $browser = 'غير معروف';
        }

        /*
        |--------------------------------------------------------------------------
        | Device Fingerprint
        |--------------------------------------------------------------------------
        */

        $deviceHash = hash(
            'sha256',
            implode('|', [
                $userAgent,
                $this->request->header('Accept-Language', ''),
                $this->request->header('Sec-CH-UA-Platform', ''),
                $this->request->header('Sec-CH-UA-Mobile', ''),
            ])
        );

        /*
        |--------------------------------------------------------------------------
        | Save Login
        |--------------------------------------------------------------------------
        */

        StaffLoginLog::create([
            'staff_id'     => $staff->id,
            'ip_address'   => $this->request->ip(),
            'user_agent'   => $userAgent,
            'device_hash'  => $deviceHash,
            'browser'      => $browser,
            'platform'     => $platform,
            'device_type'  => $deviceType,
            'logged_in_at' => now(),
        ]);
    }
}