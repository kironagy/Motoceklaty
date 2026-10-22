<?php

namespace App\Http\Controllers;

use App\Models\InstallmentRequest;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class TrackingController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['code' => ['nullable', 'string', 'max:1024']]);
        $code = trim($data['code'] ?? '');
        $status = null;
        $notFound = false;
        $modelName = null;
        $timeline = [];

        if ($code !== '') {
            try {
                $normalizedCode = strtr($code, array_combine(
                    preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY),
                    str_split('01234567890123456789')
                ));
                $id = ctype_digit($normalizedCode) ? $normalizedCode : Crypt::decryptString($code);
                $application = ctype_digit($id) ? InstallmentRequest::find($id) : null;
                $status = $application ? site('tracking.statuses.' . $application->status, site('tracking.statuses.pending')) : null;
                $notFound = ! $application;
                if ($application) {
                    $modelName = $application->machine?->name;
                    $stages = ['new_request', 'pending', 'approved', 'delivered'];
                    $current = match ($application->status) { 'pending', 'work_check', 'transferred' => 1, 'approved' => 2, 'delivered' => 3, default => 0 };
                    foreach ($stages as $index => $stage) {
                        $timeline[] = [
                            'label' => site('tracking.statuses.' . $stage),
                            'done' => $index < $current,
                            'current' => $index === $current,
                        ];
                    }
                }
            } catch (DecryptException) {
                $notFound = true;
            }
        }

        return response()->view('site.tracking.index', compact('status', 'notFound', 'modelName', 'timeline'))
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
