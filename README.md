# Secure Tokens

[![Tests](https://github.com/arkham-district/secure-tokens/actions/workflows/tests.yml/badge.svg)](https://github.com/arkham-district/secure-tokens/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/arkham-district/secure-tokens.svg)](https://packagist.org/packages/arkham-district/secure-tokens)
[![License](https://img.shields.io/packagist/l/arkham-district/secure-tokens.svg)](https://packagist.org/packages/arkham-district/secure-tokens)

API key authentication for Laravel using **Ed25519 asymmetric cryptography**. A secure alternative to Sanctum with clean token formats and optional request signing.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
  - [Setup the User Model](#setup-the-user-model)
  - [Creating API Keys](#creating-api-keys)
  - [Protecting Routes](#protecting-routes)
  - [Making Authenticated Requests](#making-authenticated-requests)
  - [Checking Abilities](#checking-abilities)
  - [Revoking Keys](#revoking-keys)
  - [Listing Keys](#listing-keys)
- [Signed Requests (Ed25519)](#signed-requests-ed25519)
  - [Route Setup](#route-setup)
  - [Client-side Signing (PHP)](#client-side-signing-php)
  - [Client-side Signing (Node.js)](#client-side-signing-nodejs)
  - [Client-side Signing (cURL)](#client-side-signing-curl)
- [API Reference](#api-reference)
  - [Ed25519Service](#ed25519service)
  - [ApiKey Model](#apikey-model)
  - [HasApiKeys Trait](#hasapikeys-trait)
  - [NewApiKey DTO](#newapikey-dto)
  - [ApiKeyGuard](#apikeyguard)
  - [AuthenticateApiKey Middleware](#authenticateapikey-middleware)
  - [ValidateSignature Middleware](#validatesignature-middleware)
  - [ApiKeysServiceProvider](#apikeyserviceprovider)
  - [HasApiKeys Contract](#hasapikeys-contract)
  - [Request Macro](#request-macro)
- [Database Schema](#database-schema)
- [Configuration Reference](#configuration-reference)
- [Architecture](#architecture)
  - [Token Format](#token-format)
  - [Authentication Flow](#authentication-flow)
  - [Signature Validation Flow](#signature-validation-flow)
  - [Storage Strategy](#storage-strategy)
- [Comparison with Sanctum](#comparison-with-sanctum)
- [Testing](#testing)
- [License](#license)

## Features

- Ed25519 keypair generation (`sk_live_xxx` / `pk_live_xxx`)
- Secret key encrypted at rest, public key for lookups
- Bearer token authentication (simple mode)
- Ed25519 request signature validation (signed mode)
- Abilities/scopes per token
- Token expiration
- Multiple tokens per user
- `last_used_at` tracking
- Polymorphic relationship (works with any Eloquent model)
- Configurable prefixes and environments

## Requirements

- PHP 8.2+
- Laravel 11+
- `sodium` PHP extension (built-in since PHP 7.2)

## Installation

```bash
composer require arkham-district/secure-tokens
```

Publish the config and migration:

```bash
php artisan vendor:publish --tag=api-keys-config
php artisan vendor:publish --tag=api-keys-migrations
php artisan migrate
```

Add the guard to `config/auth.php`:

```php
'guards' => [
    // ...
    'api-key' => [
        'driver' => 'api-key',
    ],
],
```

## Configuration

The configuration file is published to `config/api-keys.php`:

```php
return [
    // Token prefixes (appear in the generated keys)
    'prefix' => [
        'secret' => env('API_KEYS_SECRET_PREFIX', 'sk'),
        'public' => env('API_KEYS_PUBLIC_PREFIX', 'pk'),
    ],

    // Valid environment identifiers
    'environments' => ['live', 'test'],

    // Default expiration in minutes (null = never expires)
    'expiration' => null,

    // Require Ed25519 signature on all requests (global toggle)
    'require_signature' => false,
];
```

## Usage

### Setup the User Model

Add the trait and interface to any Eloquent model that should own API keys:

```php
use ArkhamDistrict\ApiKeys\Contracts\HasApiKeys as HasApiKeysContract;
use ArkhamDistrict\ApiKeys\HasApiKeys;

class User extends Authenticatable implements HasApiKeysContract
{
    use HasApiKeys;
}
```

### Creating API Keys

```php
// Create with default abilities (wildcard) and no expiration
$apiKey = $user->createApiKey('Production', 'live');

// Access the keys (only available at creation time)
$apiKey->secretKey; // "sk_live_xxxxx" — give this to the client
$apiKey->publicKey; // "pk_live_xxxxx"
$apiKey->apiKey;    // The persisted ApiKey Eloquent model

// Create with specific abilities
$apiKey = $user->createApiKey('Read Only', 'live', ['invoices:read', 'customers:read']);

// Create with expiration
$apiKey = $user->createApiKey('Temp Key', 'test', ['*'], now()->addDays(30));
```

> **Important:** The full prefixed secret key (`sk_live_xxxxx`) is only returned at creation time. It cannot be retrieved later because only the raw key (without prefix) is stored encrypted in the database.

### Protecting Routes

**Option 1 — Laravel's built-in auth middleware** (uses the guard name):

```php
Route::middleware('auth:api-key')->group(function () {
    Route::get('/invoices', [InvoiceController::class, 'index']);
});
```

**Option 2 — Package middleware alias** (standalone, does not rely on `config/auth.php`):

```php
Route::middleware('auth.api-key')->group(function () {
    Route::get('/invoices', [InvoiceController::class, 'index']);
});
```

Both approaches return a `401 Unauthenticated` JSON response for invalid or missing tokens.

### Making Authenticated Requests

```bash
curl -H "Authorization: Bearer sk_live_xxxxx" https://api.example.com/invoices
```

### Checking Abilities

```php
Route::middleware('auth:api-key')->get('/invoices', function (Request $request) {
    $apiKey = $request->apiKey();

    if ($apiKey->can('invoices:read')) {
        // The key has the "invoices:read" ability
    }

    if ($apiKey->cant('invoices:delete')) {
        abort(403, 'Insufficient permissions.');
    }

    // Keys with ["*"] abilities pass all checks
});
```

### Revoking Keys

```php
// Revoke a specific key by ID
$user->apiKeys()->where('id', $keyId)->delete();

// Revoke all keys for a user
$user->apiKeys()->delete();
```

### Listing Keys

```php
$keys = $user->apiKeys()->get();

foreach ($keys as $key) {
    $key->id;           // 1
    $key->name;         // "Production"
    $key->prefix;       // "sk_live_"
    $key->abilities;    // ["invoices:read", "invoices:write"]
    $key->last_used_at; // 2024-01-15 10:30:00
    $key->expires_at;   // null (never expires)
    $key->created_at;   // 2024-01-01 00:00:00
}
```

## Signed Requests (Ed25519)

For higher security, require clients to sign the request body with their Ed25519 secret key and include the signature in the `X-Signature` header.

### Route Setup

```php
Route::middleware(['auth:api-key', 'verify-signature'])->post('/payments', function () {
    // The request body has been cryptographically verified
});
```

> **Note:** Always place `auth:api-key` before `verify-signature` so the API key is resolved before the signature is validated.

### Client-side Signing (PHP)

```php
$body = json_encode(['amount' => 100, 'currency' => 'USD']);

// The raw secret key is the part after the prefix: sk_live_{THIS_PART}
$rawSecretKey = 'base64_encoded_secret_key';
$secretKeyBin = sodium_base642bin($rawSecretKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
$signature = sodium_crypto_sign_detached($body, $secretKeyBin);
$signatureB64 = sodium_bin2base64($signature, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

$response = Http::withHeaders([
    'Authorization' => 'Bearer sk_live_xxxxx',
    'X-Signature' => $signatureB64,
])->withBody($body, 'application/json')->post('https://api.example.com/payments');
```

### Client-side Signing (Node.js)

```javascript
const nacl = require('tweetnacl');

const body = JSON.stringify({ amount: 100, currency: 'USD' });
const rawSecretKey = Buffer.from('base64_encoded_secret_key', 'base64url');
const signature = nacl.sign.detached(Buffer.from(body), rawSecretKey);
const signatureB64 = Buffer.from(signature).toString('base64url');

const response = await fetch('https://api.example.com/payments', {
    method: 'POST',
    headers: {
        'Authorization': 'Bearer sk_live_xxxxx',
        'X-Signature': signatureB64,
        'Content-Type': 'application/json',
    },
    body,
});
```

### Client-side Signing (cURL)

```bash
# Assuming $SIGNATURE is pre-computed
curl -X POST https://api.example.com/payments \
  -H "Authorization: Bearer sk_live_xxxxx" \
  -H "X-Signature: $SIGNATURE" \
  -H "Content-Type: application/json" \
  -d '{"amount": 100, "currency": "USD"}'
```

---

## API Reference

### Ed25519Service

**Namespace:** `ArkhamDistrict\ApiKeys\Services\Ed25519Service`

Service for Ed25519 cryptographic operations. Registered as a singleton in the container.

| Method | Parameters | Returns | Description |
|---|---|---|---|
| `generateKeypair()` | — | `array{secret_key: string, public_key: string}` | Generate a raw Ed25519 keypair. Keys are URL-safe Base64 encoded. |
| `generatePrefixedKeypair()` | `string $secretPrefix`, `string $publicPrefix`, `string $environment` | `array{secret_key: string, public_key: string, raw_secret_key: string, raw_public_key: string}` | Generate a keypair with human-readable prefixes (e.g., `sk_live_xxx`). |
| `sign()` | `string $message`, `string $base64SecretKey` | `string` | Sign a message, returns Base64 detached signature. |
| `verify()` | `string $message`, `string $base64Signature`, `string $base64PublicKey` | `bool` | Verify a detached signature. Returns `false` on any failure. |

**Example:**

```php
$service = app(Ed25519Service::class);

// Generate keypair
$keypair = $service->generateKeypair();
// ['secret_key' => 'base64...', 'public_key' => 'base64...']

// Generate prefixed keypair
$prefixed = $service->generatePrefixedKeypair('sk', 'pk', 'live');
// ['secret_key' => 'sk_live_xxx', 'public_key' => 'pk_live_xxx', 'raw_secret_key' => '...', 'raw_public_key' => '...']

// Sign and verify
$signature = $service->sign('payload', $keypair['secret_key']);
$valid = $service->verify('payload', $signature, $keypair['public_key']); // true
```

---

### ApiKey Model

**Namespace:** `ArkhamDistrict\ApiKeys\Models\ApiKey`

Eloquent model representing an API key in the `api_keys` table.

#### Properties

| Property | Type | Description |
|---|---|---|
| `$id` | `int` | Primary key. |
| `$tokenable_type` | `string` | Polymorphic owner class (e.g., `App\Models\User`). |
| `$tokenable_id` | `int` | Polymorphic owner ID. |
| `$name` | `string` | Human-readable label. |
| `$prefix` | `string` | Token prefix with environment (e.g., `sk_live_`). |
| `$secret_key` | `string` | Ed25519 secret key (auto-encrypted/decrypted via Laravel). |
| `$public_key` | `string` | Ed25519 public key (plain text, unique). |
| `$abilities` | `array\|null` | JSON array of granted abilities. `null` or `["*"]` = all. |
| `$last_used_at` | `Carbon\|null` | Last authenticated request timestamp. |
| `$expires_at` | `Carbon\|null` | Expiration timestamp. `null` = never. |
| `$created_at` | `Carbon` | Creation timestamp. |
| `$updated_at` | `Carbon` | Last update timestamp. |

#### Casts

| Attribute | Cast |
|---|---|
| `abilities` | `array` (JSON) |
| `secret_key` | `encrypted` |
| `last_used_at` | `datetime` |
| `expires_at` | `datetime` |

#### Methods

| Method | Parameters | Returns | Description |
|---|---|---|---|
| `tokenable()` | — | `MorphTo` | Polymorphic relationship to the owning model. |
| `isExpired()` | — | `bool` | `true` if `expires_at` is set and in the past. |
| `can()` | `string $ability` | `bool` | `true` if the ability is granted (or wildcard). |
| `cant()` | `string $ability` | `bool` | Inverse of `can()`. |

**Example:**

```php
$apiKey = $user->apiKeys()->first();

$apiKey->isExpired();              // false
$apiKey->can('invoices:read');     // true
$apiKey->cant('invoices:delete');  // true
$apiKey->tokenable;                // User model instance
```

---

### HasApiKeys Trait

**Namespace:** `ArkhamDistrict\ApiKeys\HasApiKeys`

Trait to be used on Eloquent models to enable API key management.

| Method | Parameters | Returns | Description |
|---|---|---|---|
| `apiKeys()` | — | `MorphMany` | Polymorphic relationship returning all API keys for the model. |
| `createApiKey()` | `string $name`, `string $environment = 'live'`, `array $abilities = ['*']`, `?DateTimeInterface $expiresAt = null` | `NewApiKey` | Generate an Ed25519 keypair, persist it, and return the DTO. |

#### `createApiKey()` Parameters

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$name` | `string` | *(required)* | Human-readable label (e.g., "Production"). |
| `$environment` | `string` | `'live'` | Environment identifier (e.g., `live`, `test`). |
| `$abilities` | `array` | `['*']` | Granted abilities. `['*']` grants all. |
| `$expiresAt` | `?DateTimeInterface` | `null` | Expiration datetime. Falls back to `config('api-keys.expiration')`. |

**Example:**

```php
// All API keys for the user
$user->apiKeys()->get();
$user->apiKeys()->count();
$user->apiKeys()->where('name', 'Production')->first();

// Create new key
$new = $user->createApiKey('My Key', 'live', ['read', 'write'], now()->addDays(90));
```

---

### NewApiKey DTO

**Namespace:** `ArkhamDistrict\ApiKeys\NewApiKey`

Read-only Data Transfer Object returned by `createApiKey()`.

| Property | Type | Description |
|---|---|---|
| `$apiKey` | `ApiKey` | The persisted Eloquent model. |
| `$secretKey` | `string` | Full prefixed secret key (e.g., `sk_live_xxxxx`). **Only available at creation time.** |
| `$publicKey` | `string` | Full prefixed public key (e.g., `pk_live_xxxxx`). |

---

### ApiKeyGuard

**Namespace:** `ArkhamDistrict\ApiKeys\Guards\ApiKeyGuard`

Custom authentication guard implementing Laravel's `Illuminate\Contracts\Auth\Guard` interface.

| Method | Parameters | Returns | Description |
|---|---|---|---|
| `check()` | — | `bool` | `true` if a valid API key was provided. |
| `guest()` | — | `bool` | `true` if no valid API key was provided. |
| `user()` | — | `?Authenticatable` | Resolve and return the authenticated user, or `null`. |
| `id()` | — | `int\|string\|null` | Get the authenticated user's ID. |
| `validate()` | `array $credentials` | `bool` | Always returns `false` (not supported). |
| `hasUser()` | — | `bool` | `true` if a user has been resolved or manually set. |
| `setUser()` | `Authenticatable $user` | `static` | Manually set the authenticated user. |
| `getApiKey()` | — | `?ApiKey` | Get the resolved ApiKey model for the current request. |

**Accessing the guard:**

```php
$guard = auth('api-key');
$guard->check();      // bool
$guard->user();       // User|null
$guard->id();         // int|null
$guard->getApiKey();  // ApiKey|null
```

---

### AuthenticateApiKey Middleware

**Namespace:** `ArkhamDistrict\ApiKeys\Middleware\AuthenticateApiKey`

**Alias:** `auth.api-key`

| Method | Parameters | Returns | Description |
|---|---|---|---|
| `handle()` | `Request $request`, `Closure $next` | `Response` | Authenticate via `api-key` guard. Returns 401 JSON on failure. |

**Response on failure:**

```json
{ "message": "Unauthenticated." }
```

---

### ValidateSignature Middleware

**Namespace:** `ArkhamDistrict\ApiKeys\Middleware\ValidateSignature`

**Alias:** `verify-signature`

| Method | Parameters | Returns | Description |
|---|---|---|---|
| `handle()` | `Request $request`, `Closure $next` | `Response` | Validate `X-Signature` header against request body using Ed25519. |

**Response on failure:**

| Scenario | Status | Body |
|---|---|---|
| Missing `X-Signature` header | 401 | `{ "message": "Missing X-Signature header." }` |
| No authenticated API key | 401 | `{ "message": "Unauthenticated." }` |
| Invalid signature | 401 | `{ "message": "Invalid signature." }` |

---

### ApiKeysServiceProvider

**Namespace:** `ArkhamDistrict\ApiKeys\ApiKeysServiceProvider`

Auto-discovered via `composer.json` `extra.laravel.providers`.

#### Registration Phase (`register()`)

- Merges default config from `config/api-keys.php`.
- Binds `Ed25519Service` as a singleton.

#### Boot Phase (`boot()`)

| Internal Method | Description |
|---|---|
| `registerMigrations()` | Auto-loads migrations from `database/migrations/`. |
| `registerPublishing()` | Registers `api-keys-config` and `api-keys-migrations` publish tags. |
| `registerGuard()` | Extends Laravel Auth with the `api-key` driver. |
| `registerMiddleware()` | Registers `auth.api-key` and `verify-signature` middleware aliases. |
| `registerRequestMacro()` | Adds the `apiKey()` macro to `Illuminate\Http\Request`. |

#### Publish Tags

| Tag | Description | Command |
|---|---|---|
| `api-keys-config` | Configuration file | `php artisan vendor:publish --tag=api-keys-config` |
| `api-keys-migrations` | Migration files | `php artisan vendor:publish --tag=api-keys-migrations` |

---

### HasApiKeys Contract

**Namespace:** `ArkhamDistrict\ApiKeys\Contracts\HasApiKeys`

Interface that models should implement alongside the `HasApiKeys` trait.

| Method | Parameters | Returns |
|---|---|---|
| `apiKeys()` | — | `MorphMany` |
| `createApiKey()` | `string $name`, `string $environment`, `array $abilities = ['*']`, `?DateTimeInterface $expiresAt = null` | `NewApiKey` |

---

### Request Macro

The package registers an `apiKey()` macro on `Illuminate\Http\Request`:

```php
$request->apiKey(): ?ApiKey
```

Returns the `ApiKey` model for the current authenticated request, or `null` if unauthenticated.

---

## Database Schema

The `api_keys` table:

| Column | Type | Nullable | Description |
|---|---|---|---|
| `id` | `bigint` (auto-increment) | No | Primary key. |
| `tokenable_type` | `string` | No | Polymorphic model class. |
| `tokenable_id` | `bigint` | No | Polymorphic model ID. |
| `name` | `string` | No | Human-readable label. |
| `prefix` | `string(10)` | No | Token prefix (e.g., `sk_live_`). |
| `secret_key` | `text` | No | Encrypted Ed25519 secret key. |
| `public_key` | `string` (unique) | No | Plain text Ed25519 public key. |
| `abilities` | `text` (JSON) | Yes | Array of granted abilities. |
| `last_used_at` | `timestamp` | Yes | Last usage timestamp. |
| `expires_at` | `timestamp` | Yes | Expiration timestamp. |
| `created_at` | `timestamp` | No | Creation timestamp. |
| `updated_at` | `timestamp` | No | Update timestamp. |

**Indexes:**
- Primary key on `id`.
- Composite index on `(tokenable_type, tokenable_id)` (via `morphs()`).
- Unique index on `public_key`.

---

## Configuration Reference

| Key | Type | Default | Env Variable | Description |
|---|---|---|---|---|
| `prefix.secret` | `string` | `'sk'` | `API_KEYS_SECRET_PREFIX` | Prefix for secret keys. |
| `prefix.public` | `string` | `'pk'` | `API_KEYS_PUBLIC_PREFIX` | Prefix for public keys. |
| `environments` | `array` | `['live', 'test']` | — | Valid environment identifiers. |
| `expiration` | `int\|null` | `null` | — | Default expiration in minutes. `null` = never. |
| `require_signature` | `bool` | `false` | — | Global signature requirement toggle. |

---

## Architecture

### Token Format

Tokens follow the format `{prefix}_{environment}_{base64_key}`:

```
sk_live_<base64_encoded_secret_key>   (secret key)
pk_live_<base64_encoded_public_key>   (public key)
sk_test_<base64_encoded_secret_key>   (test secret key)
```

- **Secret key (`sk`)**: Used by the client for authentication (Bearer token) and request signing.
- **Public key (`pk`)**: Used for signature verification. Stored in plain text for database lookups.

### Authentication Flow

```
Client                          Server
  |                               |
  |  GET /api/invoices            |
  |  Authorization: Bearer sk_*   |
  |------------------------------>|
  |                               |-- Extract Bearer token
  |                               |-- Validate format (regex)
  |                               |-- Query by prefix (sk_live_)
  |                               |-- Decrypt & compare secret key
  |                               |-- Check expiration
  |                               |-- Update last_used_at
  |                               |-- Resolve tokenable (User)
  |                               |
  |  200 OK                       |
  |<------------------------------|
```

### Signature Validation Flow

```
Client                          Server
  |                               |
  |  POST /api/payments           |
  |  Authorization: Bearer sk_*   |
  |  X-Signature: {base64_sig}    |
  |  Body: {"amount": 100}        |
  |------------------------------>|
  |                               |-- Authenticate via Bearer token
  |                               |-- Extract X-Signature header
  |                               |-- Get API key's public key
  |                               |-- Verify: Ed25519(body, sig, pk)
  |                               |
  |  200 OK                       |
  |<------------------------------|
```

### Storage Strategy

| Component | Storage | Rationale |
|---|---|---|
| Secret key | Encrypted (`encrypted` cast) | Protects against database leaks. |
| Public key | Plain text (unique index) | Enables fast lookups without decryption. |
| Abilities | JSON text | Flexible, schema-less permission model. |

The secret key comparison happens in PHP (not SQL) because the value is encrypted at rest. The guard queries by prefix first, then iterates results to compare decrypted values.

---

## Comparison with Sanctum

| Feature | Laravel API Keys | Sanctum |
|---|---|---|
| Token format | `sk_live_xxxxx` (clean, prefixed) | `1\|xxxxx` (ID-prefixed) |
| Cryptography | Ed25519 (asymmetric) | SHA-256 (hash) |
| Storage | Secret encrypted, public plain | Hashed token |
| Request signing | Built-in Ed25519 signatures | Not available |
| Token types | Secret + Public keypair | Single token |
| Lookup strategy | Query by prefix, compare in PHP | Hash input, query by hash |
| Abilities | Yes | Yes |
| Expiration | Yes | Yes |
| SPA auth | No (API-only) | Yes (cookie-based) |
| Polymorphic | Yes | Yes |

### When to use Laravel API Keys

- Building APIs consumed by external services or partners.
- Need request signing for financial, webhook, or sensitive operations.
- Want clean, typed token formats with environment awareness.
- Don't need SPA cookie authentication.

### When to use Sanctum

- SPA authentication with cookies.
- Simple first-party API tokens.
- Don't need request signing or asymmetric cryptography.

---

## Testing

```bash
# Run tests
./vendor/bin/pest

# Run with coverage report
./vendor/bin/pest --coverage

# Run a specific test file
./vendor/bin/pest tests/Unit/Ed25519ServiceTest.php
./vendor/bin/pest tests/Feature/AuthenticateApiKeyTest.php
```

### Test Suite Overview

| File | Tests | Description |
|---|---|---|
| `tests/Unit/Ed25519ServiceTest.php` | 7 | Keypair generation, signing, verification, prefixes. |
| `tests/Feature/CreateApiKeyTest.php` | 7 | Key creation, prefixes, abilities, expiration, config. |
| `tests/Feature/AuthenticateApiKeyTest.php` | 11 | Bearer auth, rejection, expiration, guard methods. |
| `tests/Feature/RevokeApiKeyTest.php` | 2 | Revocation and isolation between keys. |
| `tests/Feature/AbilitiesTest.php` | 3 | Scoped abilities, wildcards, `can()`/`cant()`. |
| `tests/Feature/ValidateSignatureTest.php` | 5 | Signature validation, rejection, edge cases. |

**Total: 35 tests, 82 assertions, 100% code coverage.**

---

## License

MIT License. See [LICENSE](LICENSE) for details.
