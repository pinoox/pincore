<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Kernel\Exception;

final class SodiumBootstrap
{
    private static bool $bootstrapped = false;

    public static function ensureAvailable(): void
    {
        if (function_exists('sodium_crypto_sign_keypair')) {
            self::$bootstrapped = true;

            return;
        }

        if (!self::$bootstrapped) {
            self::loadCompat();
            self::$bootstrapped = true;
        }

        if (!function_exists('sodium_crypto_sign_keypair')) {
            throw new Exception(
                'Ed25519 signing is unavailable. Install paragonie/sodium_compat (via Composer) or enable the PHP sodium extension.',
            );
        }
    }

    public static function usingNativeExtension(): bool
    {
        self::ensureAvailable();

        return extension_loaded('sodium');
    }

    private static function loadCompat(): void
    {
        foreach (self::compatAutoloadCandidates() as $autoload) {
            if (!is_file($autoload)) {
                continue;
            }

            require_once $autoload;

            if (function_exists('sodium_crypto_sign_keypair')) {
                return;
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function compatAutoloadCandidates(): array
    {
        $candidates = [];

        if (defined('PINOOX_CORE_PATH')) {
            $candidates[] = rtrim(str_replace('\\', '/', PINOOX_CORE_PATH), '/') . '/vendor/paragonie/sodium_compat/autoload.php';
        }

        if (defined('PINOOX_BASE_PATH')) {
            $candidates[] = rtrim(str_replace('\\', '/', PINOOX_BASE_PATH), '/') . '/vendor/paragonie/sodium_compat/autoload.php';
        }

        $coreFromClass = dirname(__DIR__, 3);
        $candidates[] = $coreFromClass . '/vendor/paragonie/sodium_compat/autoload.php';

        return array_values(array_unique($candidates));
    }
}
