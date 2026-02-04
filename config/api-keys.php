<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Token Prefixes
    |--------------------------------------------------------------------------
    |
    | Define the prefixes used when generating API key tokens. The secret
    | prefix is prepended to secret keys (used by clients for authentication)
    |, and the public prefix is prepended to public keys.
    |
    | Resulting format: {prefix}_{environment}_{base64_key}
    | Example: sk_live_xxxxx, pk_test_xxxxx
    |
    */
    'prefix' => [
        'secret' => env('API_KEYS_SECRET_PREFIX', 'sk'),
        'public' => env('API_KEYS_PUBLIC_PREFIX', 'pk'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Environments
    |--------------------------------------------------------------------------
    |
    | List of valid environment identifiers that can be used when creating
    | API keys. These appear in the token format: sk_{environment}_xxxxx.
    |
    */
    'environments' => ['live', 'test'],

    /*
    |--------------------------------------------------------------------------
    | Default Expiration
    |--------------------------------------------------------------------------
    |
    | The default expiration time in minutes for newly created API keys.
    | Set to null for keys that never expire. This value is used when no
    | explicit expiration is passed to createApiKey().
    |
    | Examples:
    | null     => Never expires (default)
    | 60 => Expires in 1 hour
    | 1440 => Expires in 24 hours
    | 43200 => Expires in 30 days
    |
    */
    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Require Signature
    |--------------------------------------------------------------------------
    |
    | When set to true, all requests authenticated via API keys will also
    | require a valid Ed25519 signature in the X-Signature header. This is
    | a global setting — you can also enforce signatures per-route using
    | the 'verify-signature' middleware.
    |
    */
    'require_signature' => false,

];
