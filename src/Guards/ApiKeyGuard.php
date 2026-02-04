<?php

namespace ArkhamDistrict\ApiKeys\Guards;

use ArkhamDistrict\ApiKeys\Models\ApiKey;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;

/**
 * Custom authentication guard that resolves users via API key Bearer tokens.
 *
 * Implements Laravel's Guard contract. Extracts the Bearer token from the
 * `Authorization` header, validates the token format ({prefix}_{env}_{key}),
 * looks up the matching ApiKey record by comparing decrypted secret keys,
 * and resolves the owning tokenable (user) model.
 *
 * The guard caches the resolved user and API key per request instance, and
 * automatically resets the cache when the underlying request object changes
 * (e.g., between consecutive HTTP calls in tests).
 *
 * Registered as the `api-key` guard driver by ApiKeysServiceProvider.
 */
class ApiKeyGuard implements Guard
{
    /** @var Authenticatable|null Cached authenticated user. */
    protected ?Authenticatable $user = null;

    /** @var ApiKey|null Cached resolved API key model. */
    protected ?ApiKey $apiKey = null;

    /** @var Request|null The request instance the cache was built for. */
    protected ?Request $resolvedForRequest = null;

    /**
     * Determine if the current user is authenticated.
     *
     * @return bool True if a valid, non-expired API key was provided.
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Determine if the current user is a guest (unauthenticated).
     *
     * @return bool True if no valid API key was provided.
     */
    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * Get the currently authenticated user.
     *
     * Resolves the user by:
     * 1. Extracting the Bearer token from the request.
     * 2. Validating the token format via regex.
     * 3. Looking up the ApiKey record by prefix and decrypted secret key.
     * 4. Checking expiration.
     * 5. Updating `last_used_at` timestamp.
     * 6. Returning the owning tokenable model.
     *
     * Results are cached per request instance. If the request object changes,
     * the cache is automatically invalidated.
     *
     * @return Authenticatable|null The authenticated user, or null.
     */
    public function user(): ?Authenticatable
    {
        $currentRequest = $this->getCurrentRequest();

        // Reset cache when the request instance changes
        if ($this->resolvedForRequest !== null && $this->resolvedForRequest !== $currentRequest) {
            $this->user = null;
            $this->apiKey = null;
        }

        if ($this->user !== null) {
            return $this->user;
        }

        $this->resolvedForRequest = $currentRequest;

        $token = $this->getTokenFromRequest();

        if ($token === null) {
            return null;
        }

        $apiKey = $this->resolveApiKey($token);

        if ($apiKey === null) {
            return null;
        }

        if ($apiKey->isExpired()) {
            return null;
        }

        $this->apiKey = $apiKey;

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();

        $this->user = $apiKey->tokenable;

        return $this->user;
    }

    /**
     * Get the ID for the currently authenticated user.
     *
     * @return int|string|null The user's primary key, or null if unauthenticated.
     */
    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    /**
     * Validate a user's credentials.
     *
     * Not supported by this guard. API key authentication is stateless and
     * relies on Bearer tokens, not credential arrays.
     *
     * @param  array<string, mixed>  $credentials  Ignored.
     * @return bool Always returns false.
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    /**
     * Determine if the guard has a user instance set.
     *
     * @return bool True if a user has been resolved or manually set.
     */
    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    /**
     * Set the current user manually.
     *
     * Allows programmatic assignment of the authenticated user without
     * going through token resolution.
     *
     * @param  Authenticatable  $user  The user to set.
     * @return static
     */
    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get the ApiKey model for the current authenticated request.
     *
     * Triggers user resolution if not already performed. Returns null if
     * the request is unauthenticated.
     *
     * @return ApiKey|null The resolved API key, or null.
     */
    public function getApiKey(): ?ApiKey
    {
        $this->user();

        return $this->apiKey;
    }

    /**
     * Resolve the current request from the service container.
     *
     * Uses the container instead of the injected request to ensure the guard
     * always operates on the current request (important during testing where
     * multiple requests share the same guard instance).
     *
     * @return Request
     */
    protected function getCurrentRequest(): Request
    {
        return app('request');
    }

    /**
     * Extract and validate the Bearer token from the current request.
     *
     * The token must match the pattern `{prefix}_{env}_{key}` where prefix
     * and env are lowercase alphabetic strings. Returns null if the
     * Authorization header is missing or the token format is invalid.
     *
     * @return string|null The raw Bearer token, or null.
     */
    protected function getTokenFromRequest(): ?string
    {
        $token = $this->getCurrentRequest()->bearerToken();

        if ($token === null) {
            return null;
        }

        // Must match an expected prefix pattern: {prefix}_{env}_
        if (!preg_match('/^[a-z]+_[a-z]+_.+$/', $token)) {
            return null;
        }

        return $token;
    }

    /**
     * Resolve an ApiKey model from a Bearer token string.
     *
     * Splits the token into prefix, environment, and raw secret key components.
     * Queries the database for all keys matching the prefix, then compares
     * the decrypted secret key in PHP (since the secret is encrypted at rest
     * and cannot be compared in SQL).
     *
     * @param  string  $token  The full Bearer token (e.g., "sk_live_xxxxx").
     * @return ApiKey|null The matching API key, or null.
     */
    protected function resolveApiKey(string $token): ?ApiKey
    {
        // Token format is guaranteed by getTokenFromRequest regex: {prefix}_{env}_{key}
        $parts = explode('_', $token, 3);
        $prefix = "{$parts[0]}_{$parts[1]}_";
        $rawSecretKey = $parts[2];

        return ApiKey::query()->where('prefix', $prefix)->get()->first(function (ApiKey $apiKey) use ($rawSecretKey) {
            return $apiKey->secret_key === $rawSecretKey;
        });
    }
}
