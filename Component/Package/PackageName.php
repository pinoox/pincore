<?php

namespace Pinoox\Component\Package;

/**
 * App package naming: {tld}_{vendor}_{app} (e.g. com_pinoox_manager, io_yoosefap_ai).
 */
final class PackageName
{
    private const VALID_PATTERN = '/^[a-zA-Z]+[a-zA-Z0-9]*+[_]\s{0,1}[a-zA-Z0-9]+[_]\s{0,1}[a-zA-Z0-9]+[_]{0,1}[a-zA-Z0-9]+$/';

    public static function isValid(string $packageName): bool
    {
        return $packageName !== '' && preg_match(self::VALID_PATTERN, $packageName) === 1;
    }

    /**
     * Heuristic for CLI disambiguation (package vs short alias / username).
     */
    public static function looksLike(string $value): bool
    {
        return str_contains($value, '_') && self::isValid($value);
    }

    /**
     * App slug from package (com_pinoox_manager → manager, ir_mysite_financial → financial).
     */
    public static function appSlug(string $package): string
    {
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9]*_[^_]+_(.+)$/', $package, $matches) === 1) {
            return (string) $matches[1];
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9]*_(.+)$/', $package, $matches) === 1) {
            return (string) $matches[1];
        }

        return $package;
    }

    public static function shortLabel(string $package): string
    {
        return self::appSlug($package);
    }
}
