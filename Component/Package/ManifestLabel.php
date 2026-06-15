<?php

namespace Pinoox\Component\Package;

use Pinoox\Portal\App\AppEngine as AppEnginePortal;
use Pinoox\Portal\Lang;

/**
 * Resolve manifest label fields (app.php / theme.php).
 *
 * Supports:
 * - lang ref: '@manifest.title'
 * - locale map: ['en' => '...', 'fa' => '...']
 * - plain string
 */
final class ManifestLabel
{
    public static function isLangRef(mixed $value): bool
    {
        return ManifestLangRef::isRef($value);
    }

    public static function isLocaleMap(mixed $value): bool
    {
        if (!is_array($value) || $value === []) {
            return false;
        }

        if (array_is_list($value)) {
            return false;
        }

        foreach ($value as $key => $item) {
            if (!is_string($key) || !is_string($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $langPaths
     */
    public static function resolve(
        mixed $value,
        array $langPaths,
        ?string $locale = null,
        ?string $fallback = null,
        ?string $fallbackLocale = 'en',
    ): string {
        if (ManifestLangRef::isRef($value)) {
            $parsed = ManifestLangRef::parse($value);
            $paths = $parsed['package'] !== null
                ? ManifestLangLoader::pathsForApp($parsed['package'])
                : $langPaths;

            $resolved = ManifestLangLoader::get($paths, $parsed['key'], $locale, $fallbackLocale);

            if ($resolved !== '') {
                return $resolved;
            }

            return is_string($fallback) ? $fallback : '';
        }

        if (self::isLocaleMap($value)) {
            return self::fromLocaleMap($value, $locale);
        }

        if (is_string($value)) {
            return $value;
        }

        return is_string($fallback) ? $fallback : '';
    }

    /**
     * @param list<string> $langPaths
     * @return array<string, string>
     */
    public static function collect(mixed $value, array $langPaths): array
    {
        if (ManifestLangRef::isRef($value)) {
            $parsed = ManifestLangRef::parse($value);
            $paths = $parsed['package'] !== null
                ? ManifestLangLoader::pathsForApp($parsed['package'])
                : $langPaths;

            return ManifestLangLoader::collect($paths, $parsed['key']);
        }

        if (self::isLocaleMap($value)) {
            /** @var array<string, string> $value */
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return ['en' => $value];
        }

        return [];
    }

    /**
     * @param array<string, string> $map
     */
    public static function fromLocaleMap(array $map, ?string $locale = null): string
    {
        $locale ??= self::currentLocale();

        if ($locale !== '' && isset($map[$locale]) && is_string($map[$locale])) {
            return $map[$locale];
        }

        $first = reset($map);

        return is_string($first) ? $first : '';
    }

    public static function fallbackLocaleForPackage(string $package): string
    {
        if ($package !== '' && AppEnginePortal::exists($package)) {
            try {
                $lang = AppEnginePortal::config($package)->get('lang');

                if (is_string($lang) && $lang !== '') {
                    return $lang;
                }
            } catch (\Throwable) {
            }
        }

        try {
            return (string) Lang::getFallback();
        } catch (\Throwable) {
            return 'en';
        }
    }

    private static function currentLocale(): string
    {
        try {
            return (string) Lang::locale();
        } catch (\Throwable) {
            return 'en';
        }
    }
}
