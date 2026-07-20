<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Staff;

class ReferralController extends Controller
{
    public function handleReferral($referral_code)
    {
        $staff = Staff::where('referral_code', $referral_code)->first();

        if (!$staff) {
            abort(404);
        }

        // نخزن اسم الموظف في السيشن
        session(['referred_staff_id' => $staff->id]);

        // ✅ بدل ما نحوله على صفحة معينة، نرجعه للرئيسية
        return redirect('/')->with('success', 'تم حفظ كود الإحالة بنجاح ✅');
    }
}
