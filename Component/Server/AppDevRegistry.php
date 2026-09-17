<?php

namespace Pinoox\Component\Server;

/**
 * Tracks running Pinoox dev server instances across projects.
 *
 * Allows cross-app discovery in local development without hardcoding ports or URLs.
 */
class AppDevRegistry
{
    private static ?string $filePath = null;

    /** In-memory cache for the current request/process lifecycle */
    private static ?array $cachedEntries = null;

    public static function setFilePath(?string $path): void
    {
        self::$filePath = $path;
        self::$cachedEntries = null;
    }

    public static function filePath(): string
    {
        if (self::$filePath !== null && self::$filePath !== '') {
            return self::$filePath;
        }

        $fromEnv = getenv('PINOOX_DEV_REGISTRY_FILE');
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return trim($fromEnv);
        }

        // Use a globally shared path so apps in different project directories
        // can discover each other's dev server entries.
        $home = self::userHomeDir();
        if ($home !== null) {
            return $home . DIRECTORY_SEPARATOR . '.pinoox' . DIRECTORY_SEPARATOR . 'dev_apps.json';
        }

        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'pinoox_dev_apps.json';
    }

    /**
     * Resolve the current user's home directory in a cross-platform way.
     */
    private static function userHomeDir(): ?string
    {
        // Unix / macOS
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim(str_replace('\\', '/', $home), '/');
        }

        // Windows: USERPROFILE is the standard home directory variable
        $userProfile = getenv('USERPROFILE');
        if (is_string($userProfile) && $userProfile !== '') {
            return rtrim(str_replace('/', '\\', $userProfile), '\\');
        }

        // Windows fallback: combine HOMEDRIVE + HOMEPATH
        $homeDrive = getenv('HOMEDRIVE');
        $homePath = getenv('HOMEPATH');
        if (is_string($homeDrive) && is_string($homePath) && $homeDrive !== '') {
            return rtrim($homeDrive . $homePath, '/\\');
        }

        return null;
    }

    /**
     * Register a running app instance.
     */
    public static function register(
        string $package,
        string $host,
        int $port,
        ?int $pid = null,
        ?string $domain = null,
        bool $secure = false,
    ): void {
        // Normalize: strip optional "@path" suffix (e.g. "com_pinoox_pay@/" → "com_pinoox_pay")
        if (str_contains($package, '@')) {
            $package = explode('@', $package, 2)[0];
        }

        $package = trim($package);
        if ($package === '') {
            return;
        }

        $url = self::formatUrl($host, $port, $domain, $secure);

        $entries = self::readAll();
        $entries[$package] = [
            'package' => $package,
            'host' => $host,
            'port' => $port,
            'domain' => $domain,
            'url' => $url,
            'pid' => $pid ?? (int) getmypid(),
            'updated_at' => time(),
        ];

        self::writeAll($entries);
    }

    /**
     * Unregister an app instance.
     */
    public static function unregister(string $package): void
    {
        $package = trim($package);
        if ($package === '') {
            return;
        }

        $entries = self::readAll();
        if (isset($entries[$package])) {
            unset($entries[$package]);
            self::writeAll($entries);
        }
    }

    /**
     * Get an app's registered information if alive.
     */
    public static function get(string $package): ?array
    {
        $package = trim($package);
        if ($package === '') {
            return null;
        }

        $entries = self::all();

        return $entries[$package] ?? null;
    }

    /**
     * Get the live origin URL for a package.
     */
    public static function url(string $package): ?string
    {
        $entry = self::get($package);

        return $entry['url'] ?? null;
    }

    /**
     * Return all active and verified running apps.
     */
    public static function all(): array
    {
        $entries = self::readAll();
        $alive = [];
        $changed = false;

        foreach ($entries as $package => $entry) {
            $pid = (int) ($entry['pid'] ?? 0);
            if ($pid > 0 && !self::isProcessAlive($pid)) {
                $changed = true;
                continue;
            }

            $alive[$package] = $entry;
        }

        if ($changed) {
            self::writeAll($alive);
        }

        return $alive;
    }

    public static function purgeStale(): void
    {
        self::all();
    }

    public static function clear(): void
    {
        self::$cachedEntries = null;
        $file = self::filePath();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public static function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $cmd = sprintf('tasklist /FI "PID eq %d" 2>NUL', $pid);
            $output = (string) @shell_exec($cmd);

            return str_contains($output, (string) $pid);
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        $output = @shell_exec('kill -0 ' . $pid . ' 2>&1');

        return $output === null || trim($output) === '';
    }

    public static function formatUrl(string $host, int $port, ?string $domain = null, bool $secure = false): string
    {
        $scheme = $secure ? 'https://' : 'http://';

        if ($domain !== null && trim($domain) !== '') {
            $domain = trim($domain);
            if ($port === 80 || $port === 443) {
                return $scheme . $domain;
            }

            return $scheme . $domain . ':' . $port;
        }

        $displayHost = ($host === '0.0.0.0' || $host === '[::]') ? '127.0.0.1' : $host;

        if (($secure && $port === 443) || (!$secure && $port === 80)) {
            return $scheme . $displayHost;
        }

        return $scheme . $displayHost . ':' . $port;
    }

    private static function readAll(): array
    {
        if (self::$cachedEntries !== null) {
            return self::$cachedEntries;
        }

        $file = self::filePath();
        if (!is_file($file)) {
            return self::$cachedEntries = [];
        }

        $content = @file_get_contents($file);
        if (!is_string($content) || trim($content) === '') {
            return self::$cachedEntries = [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return self::$cachedEntries = [];
        }

        return self::$cachedEntries = $data;
    }

    private static function writeAll(array $entries): void
    {
        self::$cachedEntries = $entries;
        $file = self::filePath();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        @file_put_contents($file, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
