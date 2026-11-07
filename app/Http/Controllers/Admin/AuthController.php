<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function show(Request $request)
    {
        if (Auth::check()) {
            return redirect('/'.config('admin.path'));
        }

        return Inertia::render('Admin/Login', ['notice' => $request->session()->get('admin_notice')]);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        $throttleKey = Str::lower($data['email']).'|'.$request->ip();
        $max = config('admin.login_attempts');

        if (RateLimiter::tooManyAttempts($throttleKey, $max)) {
            AuditLog::record('login_blocked', ['email' => $data['email']], null);

            return back()->withErrors(['email' => 'Too many tries. Please wait a minute and try again.']);
        }

        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password']])) {
            RateLimiter::hit($throttleKey, 60);
            AuditLog::record('login_failed', ['email' => $data['email']], null);

            // One message for a wrong email and a wrong password.
            return back()->withErrors(['email' => 'That email and password do not match.'])->onlyInput('email');
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        $request->session()->put('admin_last_seen', now()->timestamp);
        AuditLog::record('login');

        return redirect()->intended('/'.config('admin.path'));
    }

    public function logout(Request $request)
    {
        AuditLog::record('logout');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/'.config('admin.path').'/login');
    }
}
