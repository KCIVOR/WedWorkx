<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bearer token check for /api/hermes. Constant-time comparison; no configured token means nothing gets in. */
class AuthenticateHermes
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('hermes.token');
        $given = (string) $request->bearerToken();

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['error' => 'unauthorized', 'message' => 'Missing or invalid token.'], 401, [
                'WWW-Authenticate' => 'Bearer',
            ]);
        }

        return $next($request);
    }
}
