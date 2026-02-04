<?php

namespace ArkhamDistrict\ApiKeys\Middleware;

use ArkhamDistrict\ApiKeys\Guards\ApiKeyGuard;
use ArkhamDistrict\ApiKeys\Services\Ed25519Service;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateSignature
{
    /**
     * @param  Ed25519Service  $ed25519Service  Injected Ed25519 service.
     */
    public function __construct(protected Ed25519Service $ed25519Service) {}

    /**
     * Handle an incoming request.
     *
     * Validation steps:
     * 1. Check for the `X-Signature` header — returns 401 if missing.
     * 2. Retrieve the authenticated API key from the guard — returns 401 if null.
     * 3. Verify the signature against the request body and API key's public key.
     * 4. Returns 401 with `{"message": "Invalid signature."}` if verification fails.
     *
     * @param  Request  $request  The incoming HTTP request.
     * @param  Closure  $next  The next middleware in the pipeline.
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Signature');
        if ($signature === null) {
            return response()->json(['message' => 'Missing X-Signature header.'], 401);
        }

        /** @var ApiKeyGuard $guard */
        $guard = auth('api-key');

        $apiKey = $guard->getApiKey();
        if ($apiKey === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $body = $request->getContent();

        $isValid = $this->ed25519Service->verify($body, $signature, $apiKey->public_key);
        if (!$isValid) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        return $next($request);
    }
}
