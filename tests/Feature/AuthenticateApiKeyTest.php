<?php

use ArkhamDistrict\ApiKeys\Guards\ApiKeyGuard;
use ArkhamDistrict\ApiKeys\Tests\Support\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('auth.api-key')->get('/test', function () {
        return response()->json([
            'user_id' => auth('api-key')->id(),
            'message' => 'authenticated',
        ]);
    });
});

it('authenticates a request with a valid secret key', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $newApiKey = $user->createApiKey('Production', 'live');

    $response = $this->getJson('/test', [
        'Authorization' => "Bearer {$newApiKey->secretKey}",
    ]);

    $response->assertOk()
        ->assertJson([
            'user_id' => $user->id,
            'message' => 'authenticated',
        ]);
});

it('rejects a request with an invalid secret key', function () {
    $response = $this->getJson('/test', [
        'Authorization' => 'Bearer sk_live_invalidkey123',
    ]);

    $response->assertUnauthorized();
});

it('rejects a request without authorization header', function () {
    $response = $this->getJson('/test');

    $response->assertUnauthorized();
});

it('rejects a request with an expired key', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $newApiKey = $user->createApiKey('Production', 'live', ['*'], now()->subMinute());

    $response = $this->getJson('/test', [
        'Authorization' => "Bearer {$newApiKey->secretKey}",
    ]);

    $response->assertUnauthorized();
});

it('updates last_used_at on successful authentication', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $newApiKey = $user->createApiKey('Production', 'live');

    expect($newApiKey->apiKey->last_used_at)->toBeNull();

    $this->getJson('/test', [
        'Authorization' => "Bearer {$newApiKey->secretKey}",
    ]);

    $newApiKey->apiKey->refresh();
    expect($newApiKey->apiKey->last_used_at)->not->toBeNull();
});

it('supports multiple tokens per user', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $key1 = $user->createApiKey('Production', 'live');
    $key2 = $user->createApiKey('Staging', 'test');
    $key3 = $user->createApiKey('Development', 'live');

    expect($user->apiKeys)->toHaveCount(3);

    // All tokens should authenticate
    $this->getJson('/test', ['Authorization' => "Bearer {$key1->secretKey}"])->assertOk();
    $this->getJson('/test', ['Authorization' => "Bearer {$key2->secretKey}"])->assertOk();
    $this->getJson('/test', ['Authorization' => "Bearer {$key3->secretKey}"])->assertOk();
});

it('rejects a request with malformed token format', function () {
    $response = $this->getJson('/test', [
        'Authorization' => 'Bearer not-a-valid-format',
    ]);

    $response->assertUnauthorized();
});

it('guard validate method returns false', function () {
    /** @var ApiKeyGuard $guard */
    $guard = auth('api-key');

    expect($guard->validate(['token' => 'anything']))->toBeFalse();
});

it('guard hasUser returns false when no user resolved', function () {
    /** @var ApiKeyGuard $guard */
    $guard = auth('api-key');

    expect($guard->hasUser())->toBeFalse();
});

it('guard hasUser returns true after authentication', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $newApiKey = $user->createApiKey('Production', 'live');

    $this->getJson('/test', ['Authorization' => "Bearer {$newApiKey->secretKey}"])->assertOk();

    /** @var ApiKeyGuard $guard */
    $guard = auth('api-key');

    expect($guard->hasUser())->toBeTrue();
});

it('guard setUser sets the user manually', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);

    /** @var ApiKeyGuard $guard */
    $guard = auth('api-key');
    $result = $guard->setUser($user);

    expect($guard->hasUser())->toBeTrue()
        ->and($guard->id())->toBe($user->id)
        ->and($result)->toBe($guard);
});
