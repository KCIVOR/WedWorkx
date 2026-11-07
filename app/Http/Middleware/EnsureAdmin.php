<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Signed-in admins only. Idle sessions end after admin.idle_minutes. Admin pages are never cached or indexed. */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = config('admin.path');

        if (! Auth::check()) {
            return $request->expectsJson() ? abort(401) : redirect("/$path/login");
        }

        $last = $request->session()->get('admin_last_seen');
        if ($last && now()->timestamp - $last > config('admin.idle_minutes') * 60) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect("/$path/login")->with('admin_notice', 'You were signed out after a while of no activity. Please sign in again.');
        }
        $request->session()->put('admin_last_seen', now()->timestamp);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
