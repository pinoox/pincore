<?php

namespace Pinoox\Component\Server;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Ensures a local dev hostname resolves to loopback via the system hosts file.
 */
final class HostsFileMapper
{
    public const STATUS_OK = 'ok';

    public const STATUS_ADDED = 'added';

    public const STATUS_NEEDS_ADMIN = 'needs_admin';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array{status: string, message: string}
     */
    public static function ensureLoopback(string $domain, bool $attemptFix = true): array
    {
        $domain = ServeLocalDomain::normalize($domain);

        if ($domain === null) {
            return ['status' => self::STATUS_FAILED, 'message' => 'Invalid local domain.'];
        }

        if (self::mapsToLoopback($domain)) {
            return ['status' => self::STATUS_OK, 'message' => ''];
        }

        $conflict = self::conflictingIp($domain);

        if ($conflict !== null) {
            return [
                'status' => self::STATUS_CONFLICT,
                'message' => sprintf(
                    '%s is mapped to %s in %s — remove or update that line first.',
                    $domain,
                    $conflict,
                    ServeLocalDomain::hostsFilePath(),
                ),
            ];
        }

        if (!$attemptFix) {
            return [
                'status' => self::STATUS_NEEDS_ADMIN,
                'message' => ServeLocalDomain::hostsFileEntry($domain),
            ];
        }

        $entry = ServeLocalDomain::hostsFileEntry($domain);
        $path = ServeLocalDomain::hostsFilePath();

        if (self::tryDirectAppend($path, $entry) && self::mapsToLoopback($domain)) {
            return ['status' => self::STATUS_ADDED, 'message' => $entry];
        }

        if (self::tryElevatedAppend($path, $entry, $domain) && self::mapsToLoopback($domain)) {
            return ['status' => self::STATUS_ADDED, 'message' => $entry];
        }

        return [
            'status' => self::STATUS_NEEDS_ADMIN,
            'message' => ServeLocalDomain::hostsFileEntry($domain),
        ];
    }

    public static function hostsFileNeedsElevation(?string $hostsFile = null): bool
    {
        $path = $hostsFile ?? ServeLocalDomain::hostsFilePath();

        if (!is_file($path)) {
            return true;
        }

        $handle = @fopen($path, 'a');

        if ($handle === false) {
            return true;
        }

        fclose($handle);

        return false;
    }

    public static function applyForDomain(SymfonyStyle $io, ?string $domain, bool $attemptFix = true): bool
    {
        if ($domain === null || $domain === '') {
            return true;
        }

        if ($attemptFix
            && !self::mapsToLoopback($domain)
            && self::conflictingIp($domain) === null
            && self::hostsFileNeedsElevation()
        ) {
            $io->note([
                'Updating ' . ServeLocalDomain::hostsFilePath() . ' requires administrator permission.',
                self::elevationPromptHint(),
            ]);
        }

        $result = self::ensureLoopback($domain, $attemptFix);

        return match ($result['status']) {
            self::STATUS_OK => true,
            self::STATUS_ADDED => (function () use ($io, $result, $domain): bool {
                $io->success([
                    'Added hosts entry for ' . $domain . ':',
                    '  ' . $result['message'],
                ]);

                return true;
            })(),
            self::STATUS_CONFLICT => (function () use ($io, $result): bool {
                $io->error($result['message']);

                return false;
            })(),
            self::STATUS_NEEDS_ADMIN => (function () use ($io, $result): bool {
                $io->warning([
                    'Could not update ' . ServeLocalDomain::hostsFilePath() . ' automatically.',
                    'The elevation prompt may have been cancelled or permission was denied.',
                    'Add this line manually, or approve the system prompt when re-running serve:',
                    '  ' . $result['message'],
                ]);

                return true;
            })(),
            default => (function () use ($io, $result): bool {
                $io->warning($result['message'] !== '' ? $result['message'] : 'Could not configure local domain.');

                return true;
            })(),
        };
    }

    public static function mapsToLoopback(string $domain): bool
    {
        $domain = ServeLocalDomain::normalize($domain);

        if ($domain === null) {
            return false;
        }

        if (self::fileMapsToLoopback($domain)) {
            return true;
        }

        return ServeLocalDomain::resolvesToLoopback($domain);
    }

    public static function fileMapsToLoopback(string $domain, ?string $hostsFile = null): bool
    {
        foreach (self::entriesForHost($domain, $hostsFile) as $entry) {
            if (in_array($entry['ip'], ['127.0.0.1', '::1'], true)) {
                return true;
            }
        }

        return false;
    }

    public static function conflictingIp(string $domain, ?string $hostsFile = null): ?string
    {
        foreach (self::entriesForHost($domain, $hostsFile) as $entry) {
            if (!in_array($entry['ip'], ['127.0.0.1', '::1'], true)) {
                return $entry['ip'];
            }
        }

        return null;
    }

    /**
     * @return list<array{ip: string, host: string}>
     */
    public static function entriesForHost(string $domain, ?string $hostsFile = null): array
    {
        $domain = strtolower(ServeLocalDomain::normalize($domain) ?? '');

        if ($domain === '') {
            return [];
        }

        $path = $hostsFile ?? ServeLocalDomain::hostsFilePath();

        if (!is_readable($path)) {
            return [];
        }

        $contents = @file_get_contents($path);

        if (!is_string($contents)) {
            return [];
        }

        $matches = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));

            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $line) ?: [];

            if ($parts === [] || !isset($parts[0])) {
                continue;
            }

            $ip = strtolower($parts[0]);

            for ($i = 1, $count = count($parts); $i < $count; $i++) {
                $host = strtolower($parts[$i]);

                if ($host === $domain) {
                    $matches[] = ['ip' => $ip, 'host' => $host];
                }
            }
        }

        return $matches;
    }

    private static function tryDirectAppend(string $path, string $entry): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $line = PHP_EOL . $entry;

        if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            return false;
        }

        clearstatcache(true, $path);

        return true;
    }

    private static function tryElevatedAppend(string $path, string $entry, string $domain): bool
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => self::tryWindowsElevation($path, $entry, $domain),
            'Darwin', 'Linux' => self::tryUnixElevation($path, $entry, $domain),
            default => false,
        };
    }

    private static function tryWindowsElevation(string $path, string $entry, string $domain): bool
    {
        if (!self::commandExists('powershell')) {
            return false;
        }

        $scriptPath = self::writeTempPowerShellScript($path, $entry, $domain);

        if ($scriptPath === null) {
            return false;
        }

        try {
            $psScript = str_replace("'", "''", $scriptPath);
            $command = sprintf(
                "Start-Process -FilePath powershell.exe -Verb RunAs -Wait -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File','%s')",
                $psScript,
            );
            $process = Process::fromShellCommandline(
                'powershell -NoProfile -ExecutionPolicy Bypass -Command ' . escapeshellarg($command),
            );
            $process->setTimeout(120);
            $process->run();

            clearstatcache(true, $path);

            return self::fileMapsToLoopback($domain);
        } finally {
            @unlink($scriptPath);
        }
    }

    private static function writeTempPowerShellScript(string $path, string $entry, string $domain): ?string
    {
        $scriptPath = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'pinoox-hosts-' . md5($domain) . '.ps1';
        $escapedPath = str_replace("'", "''", $path);
        $escapedEntry = str_replace("'", "''", $entry);
        $pattern = preg_quote($domain, '');

        $script = <<<PS
\$ErrorActionPreference = 'Stop'
\$path = '{$escapedPath}'
\$entry = '{$escapedEntry}'
if (-not (Test-Path -LiteralPath \$path)) {
    throw "Hosts file not found: \$path"
}
\$content = Get-Content -LiteralPath \$path -Raw -ErrorAction SilentlyContinue
if (\$null -eq \$content) { \$content = '' }
if (\$content -match '(?i)\s{$pattern}(\s|$)') { exit 0 }
Add-Content -LiteralPath \$path -Value \$entry -Encoding ascii
exit 0
PS;

        return @file_put_contents($scriptPath, $script) !== false ? $scriptPath : null;
    }

    private static function tryUnixElevation(string $path, string $entry, string $domain): bool
    {
        if (PHP_OS_FAMILY === 'Darwin' && self::tryMacOsElevation($path, $entry, $domain)) {
            return true;
        }

        if (PHP_OS_FAMILY === 'Linux' && self::commandExists('pkexec') && self::tryPkexecAppend($path, $entry, $domain)) {
            return true;
        }

        return self::trySudoElevation($path, $entry, $domain);
    }

    private static function tryMacOsElevation(string $path, string $entry, string $domain): bool
    {
        if (!self::commandExists('osascript')) {
            return false;
        }

        $shell = self::unixAppendShell($path, $entry, $domain);
        $appleScript = 'do shell script ' . escapeshellarg($shell) . ' with administrator privileges';
        $process = new Process(['osascript', '-e', $appleScript]);
        $process->setTimeout(120);
        $process->run();

        clearstatcache(true, $path);

        return self::fileMapsToLoopback($domain);
    }

    private static function tryPkexecAppend(string $path, string $entry, string $domain): bool
    {
        $shell = self::unixAppendShell($path, $entry, $domain);
        $process = new Process(['pkexec', 'sh', '-c', $shell]);
        $process->setTimeout(120);
        $process->run();

        clearstatcache(true, $path);

        return self::fileMapsToLoopback($domain);
    }

    private static function trySudoElevation(string $path, string $entry, string $domain): bool
    {
        if (!self::commandExists('sudo')) {
            return false;
        }

        $shell = self::unixAppendShell($path, $entry, $domain);

        foreach ([['sudo', '-n', 'sh', '-c', $shell], ['sudo', 'sh', '-c', $shell]] as $command) {
            $process = new Process($command);
            $process->setTimeout(120);

            if (($command[1] ?? null) !== '-n' && Process::isTtySupported()) {
                $process->setTty(true);
            }

            $process->run();

            clearstatcache(true, $path);

            if ($process->isSuccessful() && self::fileMapsToLoopback($domain)) {
                return true;
            }
        }

        return false;
    }

    private static function unixAppendShell(string $path, string $entry, string $domain): string
    {
        return sprintf(
            'grep -qE "[[:space:]]%s([[:space:]]|$)" %s 2>/dev/null || echo %s >> %s',
            preg_quote($domain, '/'),
            escapeshellarg($path),
            escapeshellarg($entry),
            escapeshellarg($path),
        );
    }

    private static function elevationPromptHint(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'Approve the UAC (Run as administrator) prompt if it appears.',
            'Darwin' => 'Enter your macOS password when the system dialog appears.',
            'Linux' => 'Approve the polkit/sudo prompt if it appears.',
            default => 'Approve the system elevation prompt if it appears.',
        };
    }

    private static function commandExists(string $command): bool
    {
        $finder = PHP_OS_FAMILY === 'Windows' ? 'where' : 'command -v';
        $process = Process::fromShellCommandline($finder . ' ' . escapeshellarg($command));
        $process->run();

        return $process->isSuccessful();
    }
}
