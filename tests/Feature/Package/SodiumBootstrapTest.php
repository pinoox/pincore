<?php

use Pinoox\Component\Package\Pinx\SodiumBootstrap;

it('bootstraps Ed25519 signing without requiring ext-sodium', function () {
    bootstrapTestSodiumCompat();

    expect(function_exists('sodium_crypto_sign_keypair'))->toBeTrue()
        ->and(function_exists('sodium_crypto_sign_detached'))->toBeTrue()
        ->and(function_exists('sodium_crypto_sign_verify_detached'))->toBeTrue();

    $keyPair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keyPair);
    $publicKey = sodium_crypto_sign_publickey($keyPair);
    $message = 'pinoox-pinx-signing-self-test';
    $signature = sodium_crypto_sign_detached($message, $secretKey);

    expect(SodiumBootstrap::usingNativeExtension())->toBeBool()
        ->and(sodium_crypto_sign_verify_detached($signature, $message, $publicKey))->toBeTrue();
});
