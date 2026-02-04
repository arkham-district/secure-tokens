<?php

use ArkhamDistrict\ApiKeys\Services\Ed25519Service;
use ArkhamDistrict\ApiKeys\Tests\Support\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['auth:api-key', 'verify-signature'])->post('/signed', function () {
        return response()->json(['ok' => true]);
    });

    // Route with only verify-signature (no auth middleware) to test null apiKey branch
    Route::middleware('verify-signature')->post('/signed-only', function () {
        return response()->json(['ok' => true]);
    });
});

it('validates a correctly signed request', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $newApiKey = $user->createApiKey('Production', 'live');

    $body = json_encode(['amount' => 100]);

    $ed25519 = app(Ed25519Service::class);
    $rawSecretKey = $user->apiKeys->first()->secret_key;
    $signature = $ed25519->sign($body, $rawSecretKey);

    $response = $this->postJson('/signed', ['amount' => 100], [
        'Authorization' => "Bearer {$newApiKey->secretKey}",
        'X-Signature' => $signature,
    ]);

    $response->assertOk()->assertJson(['ok' => true]);
});

it('rejects a request with an invalid signature', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $newApiKey = $user->createApiKey('Production', 'live');

    $response = $this->postJson('/signed', ['amount' => 100], [
        'Authorization' => "Bearer {$newApiKey->secretKey}",
        'X-Signature' => 'invalid-signature-value',
    ]);

    $response->assertUnauthorized();
});

it('rejects a request without X-Signature header', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $newApiKey = $user->createApiKey('Production', 'live');

    $response = $this->postJson('/signed', ['amount' => 100], [
        'Authorization' => "Bearer {$newApiKey->secretKey}",
    ]);

    $response->assertUnauthorized()
        ->assertJson(['message' => 'Missing X-Signature header.']);
});

it('rejects a signature signed with a different key', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $newApiKey = $user->createApiKey('Production', 'live');

    $body = json_encode(['amount' => 100]);

    // Sign with a completely different keypair
    $ed25519 = app(Ed25519Service::class);
    $otherKeypair = $ed25519->generateKeypair();
    $signature = $ed25519->sign($body, $otherKeypair['secret_key']);

    $response = $this->postJson('/signed', ['amount' => 100], [
        'Authorization' => "Bearer {$newApiKey->secretKey}",
        'X-Signature' => $signature,
    ]);

    $response->assertUnauthorized()
        ->assertJson(['message' => 'Invalid signature.']);
});

it('rejects when signature middleware runs without authenticated api key', function () {
    $response = $this->postJson('/signed-only', ['amount' => 100], [
        'X-Signature' => 'some-signature',
    ]);

    $response->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);
});
