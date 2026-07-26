<?php

namespace Pinoox\Component\Server\Share;

use Pinoox\Component\Server\ServeLocalDomain;

/**
 * Aligns URL generation with the host the user actually opened (local, LAN, or tunnel).
 */
final class ShareTunnelRequest
{
    public static function publicUrlPath(string $projectRoot): string
    {
        return ShareToolkit::binDir($projectRoot) . DIRECTORY_SEPARATOR . 'share-public-url';
    }

    public static function rememberPublicUrl(string $projectRoot, string $publicUrl): void
    {
        ShareToolkit::binDir($projectRoot);
        file_put_contents(self::publicUrlPath($projectRoot), rtrim(trim($publicUrl), '/') . PHP_EOL);
    }

    public static function forgetPublicUrl(string $projectRoot): void
    {
        $path = self::publicUrlPath($projectRoot);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public static function readPublicUrl(string $projectRoot): ?string
    {
        $path = self::publicUrlPath($projectRoot);

        if (!is_file($path)) {
            return null;
        }

        $url = trim((string) file_get_contents($path));

        return $url !== '' ? rtrim($url, '/') : null;
    }

    public static function applyToGlobals(string $projectRoot): void
    {
        $httpHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $host = self::normalizeHost($httpHost);

        if ($host === '') {
            return;
        }

        if (self::isLocalServeHost($host)) {
            self::clearProxyOverrides();

            return;
        }

        if (self::isTunnelHost($host)) {
            self::applyTunnelOrigin($host);

            return;
        }

        self::clearProxyOverrides();
    }

    private static function applyTunnelOrigin(string $host): void
    {
        $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        $forwardedSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
        $useHttps = $forwardedProto === 'https'
            || in_array($forwardedSsl, ['on', '1'], true)
            || self::isTunnelHost($host);

        if ($useHttps) {
            $_SERVER['HTTPS'] = 'on';
            $_SERVER['REQUEST_SCHEME'] = 'https';
        } else {
            self::clearHttpsFlags();
        }

        $scheme = $useHttps ? 'https' : 'http';
        $origin = $scheme . '://' . $host;

        $_ENV['HOST_PROXY'] = $origin;
        putenv('HOST_PROXY=' . $origin);
    }

    private static function clearProxyOverrides(): void
    {
        unset($_ENV['HOST_PROXY']);
        putenv('HOST_PROXY');
        self::clearHttpsFlags();
    }

    private static function clearHttpsFlags(): void
    {
        unset($_SERVER['HTTPS'], $_SERVER['REQUEST_SCHEME']);
    }

    private static function normalizeHost(string $httpHost): string
    {
        $host = strtolower(trim($httpHost));

        if ($host === '') {
            return '';
        }

        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');

            if ($end !== false) {
                return substr($host, 1, $end - 1);
            }
        }

        return preg_replace('/:\d+$/', '', $host) ?? $host;
    }

    private static function isLocalServeHost(string $host): bool
    {
        if (in_array($host, ['127.0.0.1', 'localhost', '::1', '0.0.0.0'], true)) {
            return true;
        }

        if (self::isPrivateLanHost($host)) {
            return true;
        }

        $domain = ServeLocalDomain::normalize($host);

        if ($domain !== null && ServeLocalDomain::resolvesToLoopback($domain)) {
            return true;
        }

        $envDomain = ServeLocalDomain::normalize((string) getenv('SERVER_DOMAIN'));

        if ($envDomain !== null && strcasecmp($host, $envDomain) === 0) {
            return true;
        }

        return false;
    }

    private static function isPrivateLanHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    private static function isTunnelHost(string $host): bool
    {
        if ($host === '') {
            return false;
        }

        foreach ([
            'serveousercontent.com',
            'serveo.net',
            'pinggy-free.link',
            'free.pinggy.io',
            'free.pinggy.net',
            'pinggy.io',
            'pinggy.link',
            'lhr.life',
            'localhost.run',
            'loca.lt',
            'trycloudflare.com',
            'tunnelmole.net',
            'ngrok-free.app',
            'ngrok.io',
            'bore.pub',
        ] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return str_contains($host, 'pinggy');
    }
}
