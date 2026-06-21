<?php

declare(strict_types=1);

namespace Pinoox\Support;

/**
 * Detect the primary dev app in single-app (root layout) projects.
 */
final class DevApp
{
    public static function package(?string $projectRoot = null): ?string
    {
        $fromEnv = getenv('PINX_PACKAGE') ?: getenv('PINOOX_DEV_APP') ?: null;

        if (is_string($fromEnv) && $fromEnv !== '') {
            return trim($fromEnv);
        }

        $root = $projectRoot ?? SystemConfig::rootPath();

        $fromRegistry = self::fromAppsConfig($root);
        if ($fromRegistry !== null) {
            return $fromRegistry;
        }

        return self::fromRootAppFile($root);
    }

    public static function defaultCliPackage(): string
    {
        return self::package() ?? 'platform';
    }

    private static function fromRootAppFile(string $projectRoot): ?string
    {
        $appFile = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/app.php';

        if (!is_file($appFile)) {
            return null;
        }

        $config = require $appFile;

        if (!is_array($config)) {
            return null;
        }

        $package = $config['package'] ?? null;

        return is_string($package) && $package !== '' ? $package : null;
    }

    private static function fromAppsConfig(string $projectRoot): ?string
    {
        $registryFile = self::appsRegistryFile($projectRoot);

        if (!is_file($registryFile)) {
            return null;
        }

        $config = require $registryFile;

        if (!is_array($config)) {
            return null;
        }

        $packages = $config['packages'] ?? $config['apps'] ?? [];

        if (!is_array($packages)) {
            return null;
        }

        foreach ($packages as $package => $definition) {
            if (!is_string($package)) {
                continue;
            }

            $path = is_string($definition)
                ? $definition
                : (is_array($definition) ? ($definition['path'] ?? null) : null);

            if (!is_string($path)) {
                continue;
            }

            if ($path === '~' || $path === '~/') {
                return $package;
            }
        }

        return null;
    }

    private static function appsRegistryFile(string $projectRoot): string
    {
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $override = getenv('PINOOX_PROJECT_REGISTRY_PATH');

        if (is_string($override) && $override !== '') {
            $path = trim(str_replace('\\', '/', $override));

            if (str_starts_with($path, '~/')) {
                $path = $projectRoot . '/' . substr($path, 2);
            } elseif (!preg_match('/^[A-Za-z]:\//', $path) && !str_starts_with($path, '/')) {
                $path = $projectRoot . '/' . ltrim($path, '/');
            }

            return str_ends_with($path, '.php') ? $path : $path . '/apps.config.php';
        }

        foreach ([
            $projectRoot . '/platform/apps.config.php',
            $projectRoot . '/config/apps.config.php',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $projectRoot . '/platform/apps.config.php';
    }
}
