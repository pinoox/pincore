<?php

namespace Pinoox\Component\Server;

final class InspectorRuntime
{
    public const ROUTE = '/~inspector';

    public static function isAvailable(): bool
    {
        return class_exists(\Pinoox\PinxInspector\InspectorPackage::class)
            && \Pinoox\PinxInspector\InspectorPackage::isAvailable();
    }

    public static function routerPath(): string
    {
        return \Pinoox\PinxInspector\InspectorPackage::router();
    }

    public static function enabled(): bool
    {
        return (string) getenv('PINX_INSPECTOR_ENABLED') === '1';
    }

    /**
     * @return array<string, string>
     */
    public static function environment(string $projectRoot, ?string $defaultPackage = null, bool $widget = true): array
    {
        if (!self::isAvailable()) {
            return [];
        }

        $root = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $env = [
            'PINX_INSPECTOR_ENABLED' => '1',
            'PINX_INSPECTOR_ROUTE' => self::ROUTE,
            'PINX_INSPECTOR_ROUTER' => self::routerPath(),
            'PINX_INSPECTOR_PROJECT_ROOT' => $root,
            'PINX_INSPECTOR_WIDGET' => $widget ? '1' : '0',
        ];

        if ($defaultPackage !== null && $defaultPackage !== '') {
            $env['PINX_INSPECTOR_DEFAULT_PACKAGE'] = $defaultPackage;
            $env['PINX_INSPECTOR_PACKAGE'] = $defaultPackage;
        }

        return $env;
    }

    public static function applyEnvironment(string $projectRoot, ?string $defaultPackage = null, bool $widget = true): void
    {
        foreach (self::environment($projectRoot, $defaultPackage, $widget) as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    public static function url(string $host, int $port): string
    {
        if ($host === '0.0.0.0' || $host === '[::]') {
            $host = '127.0.0.1';
        }

        return 'http://' . $host . ':' . $port . self::ROUTE;
    }

    public static function openBrowser(string $url): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Windows' => 'start "" ' . escapeshellarg($url),
            'Darwin' => 'open ' . escapeshellarg($url),
            default => 'xdg-open ' . escapeshellarg($url),
        };

        @exec($command);
    }

    public static function resolveDefaultPackage(?string $serveApp): ?string
    {
        $serveApp = trim((string) $serveApp);

        if ($serveApp === '') {
            return null;
        }

        if (str_contains($serveApp, '@')) {
            [$package] = explode('@', $serveApp, 2);

            return trim($package) !== '' ? trim($package) : null;
        }

        if (str_starts_with($serveApp, 'com_')) {
            return $serveApp;
        }

        return null;
    }
}
