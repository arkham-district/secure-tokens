<?php

namespace ArkhamDistrict\ApiKeys;

use ArkhamDistrict\ApiKeys\Models\ApiKey;
use ArkhamDistrict\ApiKeys\Services\Ed25519Service;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasApiKeys
{
    /**
     * Get all API keys belonging to this model.
     *
     * @return MorphMany<ApiKey, $this>
     */
    public function apiKeys(): MorphMany
    {
        return $this->morphMany(ApiKey::class, 'tokenable');
    }

    /**
     * Create a new API key for this model.
     *
     * Generates an Ed25519 keypair, stores the secret key encrypted and the
     * public key in plain text, and returns a NewApiKey DTO containing both
     * the prefixed keys (for the consumer) and the persisted ApiKey model.
     *
     * The prefixed secret key (e.g., "sk_live_xxxxx") is only available at
     * creation time through the returned NewApiKey instance and cannot be
     * retrieved later.
     *
     * @param  string  $name  Human-readable label (e.g., "Production", "Staging").
     * @param  string  $environment  Environment identifier (e.g., "live", "test"). Default: "live".
     * @param  array<int, string>  $abilities  List of granted abilities (e.g., ["invoices:read"]). Default: ["*"] (all).
     * @param  DateTimeInterface|null  $expiresAt  Optional expiration datetime. Falls back to `config('api-keys.expiration')`.
     *                                                Pass null with no config to never expire.
     * @return NewApiKey DTO with the persisted model and prefixed key strings.
     */
    public function createApiKey(string $name, string $environment = 'live', array $abilities = ['*'], ?DateTimeInterface $expiresAt = null): NewApiKey {
        $service = app(Ed25519Service::class);
        $config = config('api-keys');

        $keypair = $service->generatePrefixedKeypair(
            $config['prefix']['secret'],
            $config['prefix']['public'],
            $environment,
        );

        $expiresAt ??= $config['expiration']
            ? now()->addMinutes($config['expiration'])
            : null;

        $apiKey = $this->apiKeys()->create([
            'name' => $name,
            'prefix' => "{$config['prefix']['secret']}_{$environment}_",
            'secret_key' => $keypair['raw_secret_key'],
            'public_key' => $keypair['raw_public_key'],
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return new NewApiKey(
            apiKey: $apiKey,
            secretKey: $keypair['secret_key'],
            publicKey: $keypair['public_key'],
        );
    }
}
