<?php

use ArkhamDistrict\ApiKeys\Tests\Support\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('auth:api-key')->get('/test', fn () => response()->json(['ok' => true]));
});

it('revokes an API key by deleting it', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $newApiKey = $user->createApiKey('Production', 'live');

    // First, verify it works
    $this->getJson('/test', ['Authorization' => "Bearer {$newApiKey->secretKey}"])->assertOk();

    // Revoke (delete) the key
    $user->apiKeys()->where('id', $newApiKey->apiKey->id)->delete();

    // Now it should be rejected
    $this->getJson('/test', ['Authorization' => "Bearer {$newApiKey->secretKey}"])->assertUnauthorized();
});

it('revoking one key does not affect other keys', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $key1 = $user->createApiKey('Key 1', 'live');
    $key2 = $user->createApiKey('Key 2', 'live');

    // Delete key 1
    $user->apiKeys()->where('id', $key1->apiKey->id)->delete();

    // Key 1 should be rejected, key 2 should still work
    $this->getJson('/test', ['Authorization' => "Bearer {$key1->secretKey}"])->assertUnauthorized();
    $this->getJson('/test', ['Authorization' => "Bearer {$key2->secretKey}"])->assertOk();
});
