<?php

namespace Pinoox\Component\Package\Routing;

use Pinoox\Support\SystemConfig;

class DomainMatcher
{

    private const RESERVED_KEYS = ['default', 'hosts'];

    private static ?array $config = null;

    public static function reset(): void
    {
        self::$config = null;
    }

    /**
     * @internal test helper
     */
    public static function useConfig(array $config): void
    {
        self::$config = $config;
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        $config = $this->load();

        if ($key === null) {
            return $config;
        }

        return $config[$key] ?? $default;
    }

    public function defaultHost(): ?string
    {
        $default = $this->config('default');

        if (!is_string($default)) {
            return null;
        }

        $default = trim($default);

        return $default !== '' ? self::normalizeHost($default) : null;
    }

    public function hostMap(): array
    {
        $map = [];

        foreach ($this->config('hosts', []) as $host => $target) {
            if (is_string($host) && $host !== '') {
                $map[$host] = $target;
            }
        }

        foreach ($this->load() as $key => $value) {
            if (in_array($key, self::RESERVED_KEYS, true)) {
                continue;
            }

            if (is_string($key) && $key !== '') {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    public function match(?string $host): ?DomainMatch
    {
        if ($host === null || $host === '') {
            return null;
        }

        $host = self::normalizeHost($host);
        $map = $this->hostMap();

        if ($map === []) {
            return null;
        }

        if (array_key_exists($host, $map)) {
            return $this->buildMatch($host, $map[$host], $host);
        }

        // If host has a port and exact match failed, try matching host-only
        if (str_contains($host, ':')) {
            $hostOnly = explode(':', $host, 2)[0];
            if (array_key_exists($hostOnly, $map)) {
                return $this->buildMatch($host, $map[$hostOnly], $hostOnly);
            }
        }

        $patterns = array_filter(array_keys($map), static fn(string $pattern): bool => str_contains($pattern, '*'));
        usort($patterns, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($patterns as $pattern) {
            $subdomain = self::matchWildcard($pattern, $host);
            if ($subdomain === false && str_contains($host, ':')) {
                if (!str_contains($pattern, ':')) {
                    $subdomain = self::matchWildcard($pattern, explode(':', $host, 2)[0]);
                }
            }
            if ($subdomain === false) {
                continue;
            }

            return $this->buildMatch($host, $map[$pattern], $host, is_string($subdomain) ? $subdomain : null, $pattern);
        }

        return null;
    }

    /**
     * Find dedicated host/origin for a package (reverse host lookup).
     */
    public function hostForPackage(string $packageName): ?string
    {
        $packageName = trim($packageName);
        if ($packageName === '') {
            return null;
        }

        foreach ($this->hostMap() as $host => $target) {
            $targetPackage = is_string($target) ? $target : ($target['package'] ?? $target['app'] ?? null);
            if (is_string($targetPackage) && $targetPackage === $packageName) {
                if (!str_contains($host, '*')) {
                    return $host;
                }
            }
        }

        return null;
    }

    /**
     * Build full URL for a package mapped in domain.config.php.
     */
    public function urlForPackage(string $packageName, bool $secure = false): ?string
    {
        $host = $this->hostForPackage($packageName);
        if ($host === null) {
            return null;
        }

        $scheme = $secure ? 'https://' : 'http://';

        return $scheme . $host;
    }

    /**
     * Any host that is not explicitly mapped in domain.config.php is treated
     * as the default domain and uses path routing from app-router.config.php.
     */
    public function isDefaultHost(?string $host): bool
    {
        if ($host === null || $host === '') {
            return true;
        }

        return $this->match($host) === null;
    }

    /**
     * True when the request host equals the configured canonical default host.
     */
    public function isCanonicalDefaultHost(?string $host): bool
    {
        if ($host === null || $host === '') {
            return false;
        }

        $default = $this->defaultHost();

        if ($default === null) {
            return false;
        }

        return self::normalizeHost($host) === $default;
    }

    /**
     * @return array{mode: string, host: string, canonical_default: string|null, package: string|null}
     */
    public function resolve(?string $host): array
    {
        $host = self::normalizeHost((string) $host);
        $match = $this->match($host);

        return [
            'mode' => $match === null ? 'default' : 'mapped',
            'host' => $host,
            'canonical_default' => $this->defaultHost(),
            'package' => $match?->package,
        ];
    }

    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return '';
        }

        $port = null;

        if (str_contains($host, '://')) {
            $parsedHost = parse_url($host, PHP_URL_HOST);
            $parsedPort = parse_url($host, PHP_URL_PORT);
            if (is_string($parsedHost) && $parsedHost !== '') {
                $host = $parsedHost;
            }
            if (is_int($parsedPort) && $parsedPort !== 80 && $parsedPort !== 443) {
                $port = $parsedPort;
            }
        }

        if ($port === null && str_contains($host, ':')) {
            $parts = explode(':', $host, 2);
            $host = $parts[0];
            $portPart = $parts[1] ?? '';
            if (is_numeric($portPart)) {
                $p = (int) $portPart;
                if ($p !== 80 && $p !== 443) {
                    $port = $p;
                }
            }
        }

        $normalized = rtrim($host, '.');

        return $port !== null ? $normalized . ':' . $port : $normalized;
    }

    private function buildMatch(
        string $host,
        mixed $target,
        string $matchedHost,
        ?string $subdomain = null,
        ?string $pattern = null,
    ): ?DomainMatch {
        if (is_string($target)) {
            $package = trim($target);

            return $package !== '' ? new DomainMatch($matchedHost, $package, '/', $subdomain, $pattern) : null;
        }

        if (!is_array($target)) {
            return null;
        }

        $package = trim((string)($target['package'] ?? $target['app'] ?? ''));

        if ($package === '') {
            return null;
        }

        $path = AppRouteMatcher::normalize((string)($target['path'] ?? '/'));

        if ($subdomain === null) {
            $capture = $target['subdomain'] ?? $target['capture'] ?? null;
            if (is_string($capture) && $capture !== '' && $capture !== '{sub}') {
                $subdomain = $capture;
            }
        }

        return new DomainMatch($matchedHost, $package, $path, $subdomain, $pattern);
    }

    private static function matchWildcard(string $pattern, string $host): string|false|null
    {
        $pattern = self::normalizeHost($pattern);

        if (!str_contains($pattern, '*')) {
            return false;
        }

        $regex = '/^' . str_replace('\*', '([a-z0-9-]+)', preg_quote($pattern, '/')) . '$/i';

        if (preg_match($regex, $host, $matches) !== 1) {
            return false;
        }

        return $matches[1] ?? null;
    }

    private function load(): array
    {
        if (is_array(self::$config)) {
            return self::$config;
        }

        $loaded = SystemConfig::get('domain');

        return self::$config = is_array($loaded) ? $loaded : [];
    }
}

