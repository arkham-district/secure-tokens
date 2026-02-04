<?php

namespace ArkhamDistrict\ApiKeys\Services;

/**
 * Service responsible for Ed25519 asymmetric cryptography operations.
 *
 * Provides keypair generation, message signing, and signature verification
 * using the libsodium Ed25519 algorithm. All binary data is encoded/decoded
 * using URL-safe Base64 without padding (SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING).
 */
class Ed25519Service
{
    /**
     * Generate a raw Ed25519 keypair.
     *
     * Creates a new cryptographic keypair using sodium_crypto_sign_keypair().
     * The secret key (64 bytes) and public key (32 bytes) are returned as
     * URL-safe Base64 encoded strings.
     *
     * @return array{secret_key: string, public_key: string} Base64-encoded keypair.
     */
    public function generateKeypair(): array
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        return [
            'secret_key' => sodium_bin2base64($secretKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
            'public_key' => sodium_bin2base64($publicKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
        ];
    }

    /**
     * Generate an Ed25519 keypair with prefixed, human-readable token strings.
     *
     * Wraps generateKeypair() and prepends configurable prefixes and environment
     * identifiers. For example, with prefix "sk", environment "live", the resulting
     * secret key will be formatted as: "sk_live_{base64_key}".
     *
     * @param  string  $secretPrefix  Prefix for the secret key (e.g., "sk").
     * @param  string  $publicPrefix  Prefix for the public key (e.g., "pk").
     * @param  string  $environment  Environment identifier (e.g., "live", "test").
     * @return array{secret_key: string, public_key: string, raw_secret_key: string, raw_public_key: string}
     *     - `secret_key`:     Full prefixed secret key (e.g., "sk_live_xxxxx").
     *     - `public_key`:     Full prefixed public key (e.g., "pk_live_xxxxx").
     *     - `raw_secret_key`: Base64-encoded secret key without prefix.
     *     - `raw_public_key`: Base64-encoded public key without prefix.
     */
    public function generatePrefixedKeypair(string $secretPrefix, string $publicPrefix, string $environment): array
    {
        $keypair = $this->generateKeypair();

        return [
            'secret_key' => "{$secretPrefix}_{$environment}_{$keypair['secret_key']}",
            'public_key' => "{$publicPrefix}_{$environment}_{$keypair['public_key']}",
            'raw_secret_key' => $keypair['secret_key'],
            'raw_public_key' => $keypair['public_key'],
        ];
    }

    /**
     * Sign a message using an Ed25519 secret key.
     *
     * Produces a detached signature (64 bytes) for the given message.
     * The signature does not contain the message itself.
     *
     * @param  string  $message  The plaintext message to sign.
     * @param  string  $base64SecretKey  The URL-safe Base64-encoded Ed25519 secret key (64 bytes decoded).
     * @return string URL-safe Base64-encoded detached signature.
     *
     * @throws \SodiumException If the secret key is malformed.
     */
    public function sign(string $message, string $base64SecretKey): string
    {
        $secretKey = sodium_base642bin($base64SecretKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $signature = sodium_crypto_sign_detached($message, $secretKey);

        return sodium_bin2base64($signature, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    /**
     * Verify a detached Ed25519 signature against a message and public key.
     *
     * Returns true if the signature is valid for the given message and was
     * produced by the corresponding secret key. Returns false on any failure,
     * including malformed keys or signatures.
     *
     * @param  string  $message  The original plaintext message.
     * @param  string  $base64Signature  The URL-safe Base64-encoded detached signature.
     * @param  string  $base64PublicKey  The URL-safe Base64-encoded Ed25519 public key (32 bytes decoded).
     * @return bool True if the signature is valid, false otherwise.
     */
    public function verify(string $message, string $base64Signature, string $base64PublicKey): bool
    {
        try {
            $signature = sodium_base642bin($base64Signature, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
            $publicKey = sodium_base642bin($base64PublicKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }
}
