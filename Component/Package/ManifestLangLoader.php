<?php

namespace Pinoox\Component\Package;

use Pinoox\Portal\App\AppEngine as AppEnginePortal;

/**
 * Read manifest lang files without booting the full app translator.
 *
 * Paths: {root}/lang/{locale}/{group}.lang.php
 */
final class ManifestLangLoader
{
    private const POSTFIX = '.lang.php';

    /**
     * @param list<string> $langPaths
     */
    public static function get(array $langPaths, string $key, ?string $locale = null, ?string $fallbackLocale = 'en'): string
    {
        if ($key === '') {
            return '';
        }

        [$group, $item] = self::parseKey($key);
        $locales = self::localeCandidates($locale, $fallbackLocale);

        foreach ($langPaths as $path) {
            $path = rtrim(str_replace('\\', '/', $path), '/');

            if ($path === '' || !is_dir($path)) {
                continue;
            }

            foreach ($locales as $candidate) {
                $line = self::readLine($path, $candidate, $group, $item);

                if ($line !== '') {
                    return $line;
                }
            }
        }

        return '';
    }

    /**
     * @param list<string> $langPaths
     * @return array<string, string>
     */
    public static function collect(array $langPaths, string $key): array
    {
        if ($key === '') {
            return [];
        }

        [$group, $item] = self::parseKey($key);
        $labels = [];

        foreach ($langPaths as $path) {
            $path = rtrim(str_replace('\\', '/', $path), '/');

            if ($path === '' || !is_dir($path)) {
                continue;
            }

            foreach (scandir($path) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $localeDir = $path . '/' . $entry;

                if (!is_dir($localeDir)) {
                    continue;
                }

                $line = self::readLine($path, $entry, $group, $item);

                if ($line !== '' && !isset($labels[$entry])) {
                    $labels[$entry] = $line;
                }
            }
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    public static function pathsForApp(string $package): array
    {
        if ($package === '' || !AppEnginePortal::exists($package)) {
            return [];
        }

        return [rtrim(str_replace('\\', '/', AppEnginePortal::path($package, 'lang')), '/')];
    }

    /**
     * Theme lang first, then host app lang as fallback.
     *
     * @return list<string>
     */
    public static function pathsForTheme(string $package, string $themePath): array
    {
        $paths = [];
        $themeLang = rtrim(str_replace('\\', '/', $themePath), '/') . '/lang';

        if (is_dir($themeLang)) {
            $paths[] = $themeLang;
        }

        foreach (self::pathsForApp($package) as $appLang) {
            if (!in_array($appLang, $paths, true)) {
                $paths[] = $appLang;
            }
        }

        return $paths;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function parseKey(string $key): array
    {
        $key = trim($key);

        if ($key === '') {
            return ['', ''];
        }

        if (!str_contains($key, '.')) {
            return ['manifest', $key];
        }

        [$group, $item] = explode('.', $key, 2);

        return [trim($group), trim($item)];
    }

    /**
     * @return list<string>
     */
    private static function localeCandidates(?string $locale, ?string $fallbackLocale): array
    {
        $candidates = [];

        foreach ([$locale, $fallbackLocale, 'en'] as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            if (!in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    private static function readLine(string $langRoot, string $locale, string $group, string $item): string
    {
        if ($group === '' || $item === '') {
            return '';
        }

        $file = $langRoot . '/' . $locale . '/' . $group . self::POSTFIX;

        if (!is_file($file)) {
            return '';
        }

        $data = include $file;

        if (!is_array($data) || !isset($data[$item]) || !is_string($data[$item])) {
            return '';
        }

        return $data[$item];
    }
}
