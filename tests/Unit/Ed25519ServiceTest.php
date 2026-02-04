<?php

use ArkhamDistrict\ApiKeys\Services\Ed25519Service;

beforeEach(function () {
    $this->service = new Ed25519Service();
});

it('generates a valid Ed25519 keypair', function () {
    $keypair = $this->service->generateKeypair();

    expect($keypair)
        ->toBeArray()
        ->toHaveKeys(['secret_key', 'public_key'])
        ->and($keypair['secret_key'])->toBeString()->not->toBeEmpty()
        ->and($keypair['public_key'])->toBeString()->not->toBeEmpty()
        ->and(strlen(sodium_base642bin($keypair['public_key'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)))
        ->toBe(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)
        ->and(strlen(sodium_base642bin($keypair['secret_key'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)))
        ->toBe(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
});

it('signs and verifies a message correctly', function () {
    $keypair = $this->service->generateKeypair();
    $message = 'Hello, World!';

    $signature = $this->service->sign($message, $keypair['secret_key']);

    expect($signature)->toBeString()->not->toBeEmpty();

    $isValid = $this->service->verify($message, $signature, $keypair['public_key']);

    expect($isValid)->toBeTrue();
});

it('rejects an invalid signature', function () {
    $keypair = $this->service->generateKeypair();
    $message = 'Hello, World!';

    $signature = $this->service->sign($message, $keypair['secret_key']);

    // Tamper with the message
    $isValid = $this->service->verify('Tampered message', $signature, $keypair['public_key']);
    expect($isValid)->toBeFalse();

    // Tamper with the signature
    $tamperedSignature = sodium_bin2base64(random_bytes(SODIUM_CRYPTO_SIGN_BYTES), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $isValid = $this->service->verify($message, $tamperedSignature, $keypair['public_key']);
    expect($isValid)->toBeFalse();
});

it('rejects verification with wrong public key', function () {
    $keypair1 = $this->service->generateKeypair();
    $keypair2 = $this->service->generateKeypair();
    $message = 'Hello, World!';

    $signature = $this->service->sign($message, $keypair1['secret_key']);

    $isValid = $this->service->verify($message, $signature, $keypair2['public_key']);
    expect($isValid)->toBeFalse();
});

it('generates prefixed keys correctly', function () {
    $result = $this->service->generatePrefixedKeypair('sk', 'pk', 'live');

    expect($result)
        ->toBeArray()
        ->toHaveKeys(['secret_key', 'public_key', 'raw_secret_key', 'raw_public_key'])
        ->and($result['secret_key'])->toStartWith('sk_live_')
        ->and($result['public_key'])->toStartWith('pk_live_');
});

it('generates test environment prefixed keys', function () {
    $result = $this->service->generatePrefixedKeypair('sk', 'pk', 'test');

    expect($result['secret_key'])->toStartWith('sk_test_')
        ->and($result['public_key'])->toStartWith('pk_test_');
});

it('can sign and verify using raw keys from prefixed keypair', function () {
    $result = $this->service->generatePrefixedKeypair('sk', 'pk', 'live');
    $message = '{"amount": 100}';

    $signature = $this->service->sign($message, $result['raw_secret_key']);
    $isValid = $this->service->verify($message, $signature, $result['raw_public_key']);

    expect($isValid)->toBeTrue();
});
