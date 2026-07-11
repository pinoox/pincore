<?php

namespace Pinoox\Component\Server;

/**
 * Developer-facing local hostname for php pinoox serve (hosts-file based).
 */
final class ServeLocalDomain
{
    public static function normalize(?string $domain): ?string
    {
        if ($domain === null) {
            return null;
        }

        $domain = trim($domain);

        if ($domain === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $domain) === 1) {
            $parsed = parse_url($domain);
            $domain = is_array($parsed) ? (string) ($parsed['host'] ?? '') : '';
        }

        if ($domain === '') {
            return null;
        }

        if (preg_match('/^\[(.+)]:(\d+)$/', $domain, $matches) === 1) {
            $domain = $matches[1];
        } elseif (preg_match('/^(.+):(\d+)$/', $domain, $matches) === 1 && !str_contains($domain, '::')) {
            $domain = $matches[1];
        }

        $domain = strtolower(rtrim($domain, '.'));

        if ($domain === '' || !self::isValidHostname($domain)) {
            return null;
        }

        return $domain;
    }

    public static function isValidHostname(string $domain): bool
    {
        if (strlen($domain) > 253) {
            return false;
        }

        return (bool) preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',
            $domain,
        );
    }

    public static function resolvesToLoopback(string $domain): bool
    {
        $resolved = gethostbyname($domain);

        if ($resolved === $domain) {
            return false;
        }

        return in_array($resolved, ['127.0.0.1', '::1'], true);
    }

    public static function httpUrl(string $host, int $port, bool $omitPort = false): string
    {
        $host = trim($host);

        if ($host === '') {
            $host = '127.0.0.1';
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $authority = $host;
        } elseif (str_contains($host, ':') && !str_contains($host, '.')) {
            $authority = '[' . $host . ']';
        } else {
            $authority = $host;
        }

        if ($omitPort || $port === 80) {
            return 'http://' . $authority;
        }

        return 'http://' . $authority . ':' . $port;
    }

    public static function browserHttpUrl(?string $domain, string $host, int $port): string
    {
        $domain = self::normalize($domain);

        if ($domain !== null) {
            return self::httpUrl($domain, $port, true);
        }

        return self::httpUrl($host, $port);
    }

    public static function proxyHttpUrl(?string $domain, string $host, int $port): string
    {
        if (self::normalize($domain) !== null) {
            return self::httpUrl('127.0.0.1', $port);
        }

        return self::httpUrl(self::publicHostForProxy($host), $port);
    }

    private static function publicHostForProxy(string $host): string
    {
        $host = trim($host);

        if ($host === '0.0.0.0' || $host === '[::]') {
            return '127.0.0.1';
        }

        return $host !== '' ? $host : '127.0.0.1';
    }

    public static function hostsFileEntry(string $domain): string
    {
        return '127.0.0.1 ' . $domain;
    }

    public static function hostsFilePath(): string
    {
        return PHP_OS_FAMILY === 'Windows'
            ? 'C:\\Windows\\System32\\drivers\\etc\\hosts'
            : '/etc/hosts';
    }
}
