<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class StaffAuthController extends Controller
{
    // تسجيل (register)
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:staff,email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $staff = Staff::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        // اختياري: تسجيل دخول فورًا
        Auth::guard('web')->login($staff);

        return response()->json(['message' => 'تم التسجيل', 'staff' => $staff], 201);
    }

    // تسجيل دخول (login)
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();
            return response()->json(['message' => 'تم تسجيل الدخول']);
        }

        throw ValidationException::withMessages([
            'email' => ['بيانات الدخول غير صحيحة.'],
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return response()->json(['message' => 'تم تسجيل الخروج']);
    }
}
