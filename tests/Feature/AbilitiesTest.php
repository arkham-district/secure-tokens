<?php

use ArkhamDistrict\ApiKeys\Tests\Support\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('auth:api-key')->get('/test', function () {
        return response()->json([
            'can_read' => request()->apiKey()->can('invoices:read'),
            'can_write' => request()->apiKey()->can('invoices:write'),
            'can_delete' => request()->apiKey()->can('invoices:delete'),
        ]);
    });
});

it('respects specific abilities', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $newApiKey = $user->createApiKey('Production', 'live', ['invoices:read', 'invoices:write']);

    $response = $this->getJson('/test', [
        'Authorization' => "Bearer {$newApiKey->secretKey}",
    ]);

    $response->assertOk()
        ->assertJson([
            'can_read' => true,
            'can_write' => true,
            'can_delete' => false,
        ]);
});

it('wildcard ability grants all permissions', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $newApiKey = $user->createApiKey('Production', 'live', ['*']);

    $response = $this->getJson('/test', [
        'Authorization' => "Bearer {$newApiKey->secretKey}",
    ]);

    $response->assertOk()
        ->assertJson([
            'can_read' => true,
            'can_write' => true,
            'can_delete' => true,
        ]);
});

it('cant method returns inverse of can', function () {
    $user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $newApiKey = $user->createApiKey('Production', 'live', ['invoices:read']);

    $apiKey = $user->apiKeys->first();
    expect($apiKey->can('invoices:read'))->toBeTrue()
        ->and($apiKey->cant('invoices:read'))->toBeFalse()
        ->and($apiKey->can('invoices:write'))->toBeFalse()
        ->and($apiKey->cant('invoices:write'))->toBeTrue();
});
