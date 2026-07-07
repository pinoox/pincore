<?php

namespace Pinoox\Component\Package;

/**
 * App package naming: {scope}_{owner}_{app} or {scope}_{owner}_{app}_{module}.
 *
 * Examples: com_pinoox_manager, io_yoosefap_ai, ir_mysite_financial_panel
 */
final class PackageName
{
    public const MAX_LENGTH = 64;

    public const SCOPE_MIN = 2;

    public const SCOPE_MAX = 10;

    private const VALID_PATTERN = '/^[a-z][a-z0-9]{1,9}_[a-z0-9]+_[a-z0-9]+(_[a-z0-9]+)?$/';

    /**
     * Canonical lowercase form (trim, strip BOM, collapse invalid chars).
     */
    public static function normalize(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '', $value) ?? $value;
        $value = preg_replace('/_+/', '_', $value) ?? $value;

        return trim($value, '_');
    }

    public static function canonical(string $packageName): string
    {
        return self::normalize($packageName);
    }

    public static function isValid(string $packageName): bool
    {
        $canonical = self::normalize($packageName);

        if ($canonical === '' || strlen($canonical) > self::MAX_LENGTH) {
            return false;
        }

        return preg_match(self::VALID_PATTERN, $canonical) === 1;
    }

    public static function equals(string $a, string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
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
        $package = self::normalize($package);

        if (preg_match('/^[a-z][a-z0-9]*_[^_]+_(.+)$/', $package, $matches) === 1) {
            return (string) $matches[1];
        }

        if (preg_match('/^[a-z][a-z0-9]*_(.+)$/', $package, $matches) === 1) {
            return (string) $matches[1];
        }

        return $package;
    }

    public static function shortLabel(string $package): string
    {
        return self::appSlug($package);
    }

    /**
     * @return list<string>
     */
    public static function segments(string $package): array
    {
        $canonical = self::normalize($package);

        if ($canonical === '') {
            return [];
        }

        return explode('_', $canonical);
    }

    public static function formatHint(): string
    {
        return '{scope}_{owner}_{app} or {scope}_{owner}_{app}_{module}';
    }
}
