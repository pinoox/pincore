<?php

namespace Pinoox\Component\Package;

use Pinoox\Portal\App\App;
use Pinoox\Portal\App\AppEngine as AppEnginePortal;

/**
 * App folder manifest (app.php).
 */
final class AppManifest
{
    public const FILE = 'app.php';

    /**
     * @return array<string, mixed>
     */
    public static function load(?string $package = null): array
    {
        $package = self::resolvePackage($package);

        if ($package === '' || !AppEnginePortal::exists($package)) {
            return [];
        }

        try {
            $data = AppEnginePortal::config($package)->all();
        } catch (\Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    public static function get(?string $package, ?string $key = null, mixed $default = null): mixed
    {
        return ManifestConfig::get(self::load($package), $key, $default);
    }

    public static function package(?string $package = null): string
    {
        $config = self::load($package);

        return (string) ($config['package'] ?? self::resolvePackage($package));
    }

    public static function title(?string $package = null, ?string $locale = null): string
    {
        return self::resolveLabel('title', $package, $locale);
    }

    public static function description(?string $package = null, ?string $locale = null): string
    {
        return self::resolveLabel('description', $package, $locale, '');
    }

    public static function displayName(?string $package = null, ?string $locale = null): string
    {
        $package = self::resolvePackage($package);
        $data = self::load($package);
        $paths = ManifestLangLoader::pathsForApp($package);
        $fallbackLocale = ManifestLabel::fallbackLocaleForPackage($package);

        foreach (['title', 'name'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $resolved = ManifestLabel::resolve(
                $data[$field],
                $paths,
                $locale,
                null,
                $fallbackLocale,
            );

            if ($resolved !== '') {
                return $resolved;
            }
        }

        return $package;
    }

    /**
     * @return array{title: array<string, string>, description: array<string, string>}
     */
    public static function labels(?string $package = null): array
    {
        $package = self::resolvePackage($package);
        $data = self::load($package);
        $paths = ManifestLangLoader::pathsForApp($package);

        return [
            'title' => ManifestLabel::collect($data['title'] ?? $data['name'] ?? null, $paths),
            'description' => ManifestLabel::collect($data['description'] ?? null, $paths),
        ];
    }

    public static function resolvePackage(?string $package): string
    {
        if (is_string($package) && $package !== '') {
            return $package;
        }

        try {
            $active = App::package();

            return is_string($active) && $active !== '' ? $active : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private static function resolveLabel(string $field, ?string $package, ?string $locale, ?string $emptyFallback = null): string
    {
        $package = self::resolvePackage($package);
        $data = self::load($package);
        $paths = ManifestLangLoader::pathsForApp($package);
        $fallbackLocale = ManifestLabel::fallbackLocaleForPackage($package);
        $fallback = $emptyFallback;

        if ($field === 'title') {
            $name = $data['name'] ?? null;
            $fallback = is_string($name) && !ManifestLabel::isLangRef($name) ? $name : $package;
        }

        if (!array_key_exists($field, $data)) {
            return $field === 'title' ? self::displayName($package, $locale) : (string) ($fallback ?? '');
        }

        $resolved = ManifestLabel::resolve(
            $data[$field],
            $paths,
            $locale,
            $fallback,
            $fallbackLocale,
        );

        if ($field === 'title' && $resolved === '') {
            return self::displayName($package, $locale);
        }

        return $resolved;
    }
}
