<?php

namespace Pinoox\Component\Server;

/**
 * Tracks running and registered Pinoox dev server instances across projects.
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
        ?string $path = null,
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
        $normalizedPath = $path !== null ? rtrim(str_replace('\\', '/', $path), '/') : ($entries[$package]['path'] ?? null);

        $entries[$package] = [
            'package' => $package,
            'host' => $host,
            'port' => $port,
            'domain' => $domain,
            'url' => $url,
            'path' => $normalizedPath,
            'pid' => $pid ?? (int) getmypid(),
            'is_running' => true,
            'updated_at' => time(),
        ];

        self::writeAll($entries);
    }

    /**
     * Unregister an app instance on server shutdown.
     * Retains the project directory path in the registry so SubApps can continue
     * executing in-process from local code even when the dev server is offline.
     */
    public static function unregister(string $package): void
    {
        $package = trim($package);
        if ($package === '') {
            return;
        }

        $entries = self::readAll();
        if (isset($entries[$package])) {
            $path = $entries[$package]['path'] ?? null;
            if ($path !== null && is_dir($path)) {
                $entries[$package]['is_running'] = false;
                $entries[$package]['pid'] = null;
                $entries[$package]['url'] = null;
                $entries[$package]['port'] = null;
                $entries[$package]['updated_at'] = time();
            } else {
                unset($entries[$package]);
            }
            self::writeAll($entries);
        }
    }

    /**
     * Get an app's registered information.
     *
     * @param string $package
     * @param bool $mustBeRunning When true, only returns if dev server process is actively alive.
     */
    public static function get(string $package, bool $mustBeRunning = false): ?array
    {
        $package = trim($package);
        if ($package === '') {
            return null;
        }

        $entries = self::all($mustBeRunning);

        return $entries[$package] ?? null;
    }

    /**
     * Get the live origin URL for a package if its dev server is actively running.
     */
    public static function url(string $package): ?string
    {
        $entry = self::get($package, true);

        return $entry['url'] ?? null;
    }

    /**
     * Get the local directory path for a package if registered (running or offline) and directory exists.
     */
    public static function path(string $package): ?string
    {
        $entry = self::get($package, false);
        $path = $entry['path'] ?? null;

        if (is_string($path) && $path !== '' && is_dir($path)) {
            return $path;
        }

        return null;
    }

    /**
     * Return registered apps.
     *
     * @param bool $runningOnly When true, returns only currently alive dev servers.
     */
    public static function all(bool $runningOnly = false): array
    {
        $entries = self::readAll();
        $alive = [];
        $changed = false;

        foreach ($entries as $package => $entry) {
            $pid = (int) ($entry['pid'] ?? 0);
            $path = $entry['path'] ?? null;
            $hasValidPath = is_string($path) && $path !== '' && is_dir($path);
            $isAlive = $pid > 0 && self::isProcessAlive($pid);

            if (!$isAlive) {
                if ($hasValidPath) {
                    if (!empty($entry['is_running']) || $pid > 0 || !empty($entry['url'])) {
                        $entries[$package]['is_running'] = false;
                        $entries[$package]['pid'] = null;
                        $entries[$package]['url'] = null;
                        $entries[$package]['port'] = null;
                        $entries[$package]['updated_at'] = time();
                        $changed = true;
                    }
                } else {
                    unset($entries[$package]);
                    $changed = true;
                    continue;
                }
            } else {
                if (empty($entry['is_running'])) {
                    $entries[$package]['is_running'] = true;
                    $changed = true;
                }
            }

            if ($runningOnly) {
                if ($isAlive) {
                    $alive[$package] = $entries[$package];
                }
            } else {
                $alive[$package] = $entries[$package];
            }
        }

        if ($changed) {
            self::writeAll($entries);
        }

        return $runningOnly ? $alive : $entries;
    }

    /**
     * Return only actively running dev server instances.
     */
    public static function running(): array
    {
        return self::all(true);
    }

    public static function purgeStale(): void
    {
        self::all(false);
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