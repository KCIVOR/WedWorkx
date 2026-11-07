<?php

namespace App\Http\Middleware;

use App\Support\DevMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The /dev routes exist only while dev mode is on. Otherwise they are a plain 404, as if they were never there. */
class EnsureDevMode
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(DevMode::enabled(), 404);

        return $next($request);
    }
}
