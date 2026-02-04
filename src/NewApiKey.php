<?php

namespace ArkhamDistrict\ApiKeys;

use ArkhamDistrict\ApiKeys\Models\ApiKey;

/**
 * @property-read ApiKey $apiKey    The persisted Eloquent model.
 * @property-read string $secretKey Full prefixed secret key (e.g., "sk_live_xxxxx").
 * @property-read string $publicKey Full prefixed public key (e.g., "pk_live_xxxxx").
 */
class NewApiKey
{
    /**
     * @param  ApiKey  $apiKey  The persisted API key model.
     * @param  string  $secretKey  The full prefixed secret key for the consumer.
     * @param  string  $publicKey  The full prefixed public key for the consumer.
     */
    public function __construct(
        public readonly ApiKey $apiKey,
        public readonly string $secretKey,
        public readonly string $publicKey
    ) {}
}
