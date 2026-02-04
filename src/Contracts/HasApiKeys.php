<?php

namespace ArkhamDistrict\ApiKeys\Contracts;

use ArkhamDistrict\ApiKeys\Models\ApiKey;
use ArkhamDistrict\ApiKeys\NewApiKey;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Contract for models that can own API keys.
 *
 * Implement this interface alongside the HasApiKeys trait on any Eloquent
 * model that should be able to create and manage API keys.
 *
 * @see \ArkhamDistrict\ApiKeys\HasApiKeys
 */
interface HasApiKeys
{
    /**
     * Get all API keys belonging to this model.
     *
     * @return MorphMany<ApiKey, $this>
     */
    public function apiKeys(): MorphMany;

    /**
     * Create a new API key for this model.
     *
     * @param  string  $name  Human-readable label.
     * @param  string  $environment  Environment identifier (e.g., "live", "test").
     * @param  array<int, string>  $abilities  Granted abilities. Default: ["*"] (all).
     * @param  DateTimeInterface|null  $expiresAt  Optional expiration datetime.
     * @return NewApiKey DTO with a persisted model and prefixed keys.
     */
    public function createApiKey(string $name, string $environment, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): NewApiKey;
}
