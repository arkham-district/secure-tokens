<?php

use ArkhamDistrict\ApiKeys\NewApiKey;
use ArkhamDistrict\ApiKeys\Tests\Support\User;

it('creates an API key and returns sk_ and pk_ prefixed keys', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    $newApiKey = $user->createApiKey('Production', 'live');

    expect($newApiKey)
        ->toBeInstanceOf(NewApiKey::class)
        ->and($newApiKey->secretKey)->toStartWith('sk_live_')
        ->and($newApiKey->publicKey)->toStartWith('pk_live_');
});

it('creates a test environment API key', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    $newApiKey = $user->createApiKey('Staging', 'test');

    expect($newApiKey->secretKey)->toStartWith('sk_test_')
        ->and($newApiKey->publicKey)->toStartWith('pk_test_');
});

it('stores the API key in the database', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    $newApiKey = $user->createApiKey('Production', 'live', ['invoices:read']);

    expect($user->apiKeys)->toHaveCount(1);

    $apiKey = $user->apiKeys->first();
    expect($apiKey->name)->toBe('Production')
        ->and($apiKey->prefix)->toBe('sk_live_')
        ->and($apiKey->abilities)->toBe(['invoices:read'])
        ->and($apiKey->public_key)->not->toBeEmpty()
        ->and($apiKey->secret_key)->not->toBeEmpty();
});

it('creates API key with wildcard abilities by default', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    $newApiKey = $user->createApiKey('Production', 'live');
    $apiKey = $user->apiKeys->first();

    expect($apiKey->abilities)->toBe(['*']);
});

it('creates API key with custom expiration', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $expiresAt = now()->addDays(30);

    $newApiKey = $user->createApiKey('Production', 'live', ['*'], $expiresAt);
    $apiKey = $user->apiKeys->first();

    expect($apiKey->expires_at->timestamp)->toBe($expiresAt->timestamp);
});

it('supports custom prefixes via config', function () {
    config(['api-keys.prefix.secret' => 'secret', 'api-keys.prefix.public' => 'public']);

    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $newApiKey = $user->createApiKey('Production', 'live');

    expect($newApiKey->secretKey)->toStartWith('secret_live_')
        ->and($newApiKey->publicKey)->toStartWith('public_live_');
});

it('uses config expiration when no explicit expiration is given', function () {
    config(['api-keys.expiration' => 60]); // 60 minutes

    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $newApiKey = $user->createApiKey('Production', 'live');
    $apiKey = $user->apiKeys->first();

    expect($apiKey->expires_at)->not->toBeNull()
        ->and($apiKey->expires_at->diffInMinutes(now(), true))->toBeLessThan(61);
});
