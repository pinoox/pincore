<?php

namespace Pinoox\Component\Server;

/**
 * Pick a free TCP port for local dev servers (PHP serve, Vite, …).
 */
final class ServerPort
{
    public const DEFAULT_SERVE_PORT = 8000;

    public const DEFAULT_VITE_PORT = 5173;

    public const MAX_TRIES = 50;

    private const MIN_USER_PORT = 1024;

    /** @var list<int> Well-known dev anchors used when the preferred range is blocked. */
    private const FALLBACK_ANCHORS = [
        8035,
        8080,
        8181,
        8888,
        9000,
        9090,
        10000,
        10400,
        20000,
        30000,
        49152,
    ];

    /** @var list<array{start:int,end:int}>|null */
    private static ?array $windowsExcludedRanges = null;

    public static function preferredServePort(): int
    {
        if (self::envServePortIsSet()) {
            $port = _env('SERVER_PORT', self::DEFAULT_SERVE_PORT);

            return is_numeric($port) && (int) $port > 0 ? (int) $port : self::DEFAULT_SERVE_PORT;
        }

        return self::DEFAULT_SERVE_PORT;
    }

    public static function envServePortIsSet(): bool
    {
        if (array_key_exists('SERVER_PORT', $_ENV) && (string) $_ENV['SERVER_PORT'] !== '') {
            return true;
        }

        if (array_key_exists('SERVER_PORT', $_SERVER) && (string) $_SERVER['SERVER_PORT'] !== '') {
            return true;
        }

        $fromGetenv = getenv('SERVER_PORT');

        return is_string($fromGetenv) && $fromGetenv !== '';
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
                throw new \RuntimeException(self::explicitUnavailableMessage($explicit));
            }

            return $explicit;
        }

        $start = $preferred ?? self::preferredServePort();
        $budget = max(1, min($maxTries, 200));

        foreach (self::candidatePorts($start, $budget) as $port) {
            if (self::isAvailable($host, $port)) {
                return $port;
            }
        }

        throw new \RuntimeException(self::unavailableMessage($start, $budget));
    }

    /**
     * Build an ordered, de-duplicated list of ports to probe.
     *
     * @param list<array{start:int,end:int}>|null $windowsExcludedRanges
     *
     * @return list<int>
     */
    public static function candidatePorts(
        int $start,
        int $budget = self::MAX_TRIES,
        ?array $windowsExcludedRanges = null,
    ): array {
        $budget = max(1, min($budget, 200));
        $seen = [];
        $candidates = [];

        $push = function (int $port) use (&$seen, &$candidates, $budget, $windowsExcludedRanges): void {
            if (count($candidates) >= $budget) {
                return;
            }

            if ($port < self::MIN_USER_PORT || $port > 65535) {
                return;
            }

            if (isset($seen[$port])) {
                return;
            }

            if (self::isWindowsExcluded($port, $windowsExcludedRanges)) {
                return;
            }

            $seen[$port] = true;
            $candidates[] = $port;
        };

        $linearCap = min(25, $budget);
        $blockedStreak = 0;

        for ($offset = 0; $offset < $linearCap; $offset++) {
            $port = $start + $offset;

            if (self::isWindowsExcluded($port, $windowsExcludedRanges)) {
                $blockedStreak++;

                continue;
            }

            $blockedStreak = 0;
            $push($port);
        }

        $startExcluded = self::isWindowsExcluded($start, $windowsExcludedRanges);

        if ($startExcluded || $blockedStreak >= 5) {
            foreach (self::FALLBACK_ANCHORS as $anchor) {
                for ($offset = 0; $offset < 8; $offset++) {
                    $push($anchor + $offset);
                }
            }
        }

        $resume = $start + $linearCap;

        while (count($candidates) < $budget && $resume <= 65535) {
            $push($resume);
            $resume++;
        }

        if (count($candidates) < $budget) {
            $ephemeral = 49152;

            while (count($candidates) < $budget && $ephemeral <= 65535) {
                $push($ephemeral);
                $ephemeral++;
            }
        }

        return $candidates;
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

    /**
     * @param list<array{start:int,end:int}>|null $ranges
     */
    public static function isWindowsExcluded(int $port, ?array $ranges = null): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        foreach ($ranges ?? self::windowsExcludedRanges() as $range) {
            if ($port >= $range['start'] && $port <= $range['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{start:int,end:int}>
     */
    public static function windowsExcludedRanges(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }

        if (self::$windowsExcludedRanges !== null) {
            return self::$windowsExcludedRanges;
        }

        self::$windowsExcludedRanges = self::loadWindowsExcludedRanges();

        return self::$windowsExcludedRanges;
    }

    /**
     * @return list<array{start:int,end:int}>
     */
    public static function parseExcludedPortRangeOutput(string $output): array
    {
        $ranges = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s*$/', trim($line), $matches) !== 1) {
                continue;
            }

            $start = (int) $matches[1];
            $end = (int) $matches[2];

            if ($start <= 0 || $end <= 0 || $start > $end) {
                continue;
            }

            $ranges[] = ['start' => $start, 'end' => $end];
        }

        return $ranges;
    }

    public static function resetWindowsExcludedCache(): void
    {
        self::$windowsExcludedRanges = null;
    }

    private static function loadWindowsExcludedRanges(): array
    {
        if (!function_exists('shell_exec')) {
            return [];
        }

        $output = shell_exec('netsh interface ipv4 show excludedportrange protocol=tcp 2>NUL');

        if (!is_string($output) || trim($output) === '') {
            return [];
        }

        return self::parseExcludedPortRangeOutput($output);
    }

    private static function explicitUnavailableMessage(int $port): string
    {
        if (PHP_OS_FAMILY === 'Windows' && self::isWindowsExcluded($port)) {
            return sprintf(
                'Port %d is reserved by Windows (Hyper-V/WSL2/Docker). Pick a port outside excluded ranges — see `netsh interface ipv4 show excludedportrange protocol=tcp`.',
                $port,
            );
        }

        return sprintf('Port %d is already in use.', $port);
    }

    private static function unavailableMessage(int $start, int $attempts): string
    {
        $message = sprintf(
            'Could not find a free port after %d attempts (starting at %d).',
            $attempts,
            $start,
        );

        if (PHP_OS_FAMILY !== 'Windows') {
            return $message;
        }

        if (self::isWindowsExcluded($start)) {
            $message .= ' Windows has reserved the starting port range (often Hyper-V, WSL2, or Docker).'
                . ' Try `pinx dev --port=8080`, set SERVER_PORT in .env, or inspect reserved ranges with'
                . ' `netsh interface ipv4 show excludedportrange protocol=tcp`.';
        } else {
            $message .= ' Try another port with `--port=` or set SERVER_PORT in .env.';
        }

        return $message;
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
