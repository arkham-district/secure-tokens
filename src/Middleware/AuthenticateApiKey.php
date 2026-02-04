<?php

namespace ArkhamDistrict\ApiKeys\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that authenticates requests using the `api-key` guard.
 *
 * Checks if the request contains a valid Bearer token matching an existing,
 * non-expired API key. Returns a 401 JSON response if authentication fails.
 *
 * Registered as the `auth.api-key` middleware alias by ApiKeysServiceProvider.
 *
 * Usage in routes:
 *     Route::middleware('auth.api-key')->get('/endpoint', ...);
 *
 * Alternatively, you can use Laravel's built-in auth middleware with the guard name:
 *     Route::middleware('auth:api-key')->get('/endpoint', ...);
 */
class AuthenticateApiKey
{
    /**
     * Handle an incoming request.
     *
     * Resolves the `api-key` guard and checks for an authenticated user.
     * If the guard reports a guest (unauthenticated), returns a 401 JSON
     * response with `{"message": "Unauthenticated."}`.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @param  Closure  $next  The next middleware in the pipeline.
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = auth('api-key');

        if ($guard->guest()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
