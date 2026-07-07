<?php

namespace Pinoox\Component\Server;

/**
 * Pick a free TCP port for local dev servers (PHP serve, Vite, …).
 */
final class ServerPort
{
    public const DEFAULT_SERVE_PORT = 8000;

    public const DEFAULT_VITE_PORT = 5173;

    public const MAX_TRIES = 10;

    public static function preferredServePort(): int
    {
        $port = _env('SERVER_PORT', self::DEFAULT_SERVE_PORT);

        return is_numeric($port) && (int) $port > 0 ? (int) $port : self::DEFAULT_SERVE_PORT;
    }

    /**
     * @param int|null $explicit  When set, port must be free or an exception is thrown.
     * @param int|null $preferred Starting port when auto-selecting (defaults to SERVER_PORT or 8000).
     */
    public static function resolve(
        ?int $explicit,
        string $host = '127.0.0.1',
        ?int $preferred = null,
        int $maxTries = self::MAX_TRIES,
    ): int {
        if ($explicit !== null && $explicit > 0) {
            if (!self::isAvailable($host, $explicit)) {
                throw new \RuntimeException(sprintf('Port %d is already in use.', $explicit));
            }

            return $explicit;
        }

        $start = $preferred ?? self::preferredServePort();

        for ($offset = 0; $offset < $maxTries; $offset++) {
            $port = $start + $offset;

            if (self::isAvailable($host, $port)) {
                return $port;
            }
        }

        throw new \RuntimeException(sprintf(
            'Could not find a free port after %d attempts (starting at %d).',
            $maxTries,
            $start,
        ));
    }

    public static function isAvailable(string $host, int $port): bool
    {
        if ($port < 1 || $port > 65535) {
            return false;
        }

        $checkHost = self::checkHost($host);
        $connection = @fsockopen($checkHost, $port, $errno, $errstr, 0.2);

        if (is_resource($connection)) {
            fclose($connection);

            return false;
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_server(
            sprintf('tcp://%s:%d', $checkHost, $port),
            $errno,
            $errstr,
        );

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    private static function checkHost(string $host): string
    {
        $host = trim($host);

        if ($host === '' || $host === '0.0.0.0' || $host === '[::]' || $host === '::') {
            return '127.0.0.1';
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }
}
