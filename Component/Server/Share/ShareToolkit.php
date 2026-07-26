<?php

namespace Pinoox\Component\Server\Share;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final class ShareToolkit
{
    public static function binDir(string $projectRoot): string
    {
        $dir = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.pinoox' . DIRECTORY_SEPARATOR . 'bin';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    public static function findSsh(): ?string
    {
        return self::findInPath(PHP_OS_FAMILY === 'Windows' ? 'ssh.exe' : 'ssh');
    }

    public static function ensureEd25519KeyPair(string $privatePath): bool
    {
        $publicPath = $privatePath . '.pub';

        if (is_file($privatePath) && is_file($publicPath)) {
            return true;
        }

        $dir = dirname($privatePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (ShareToolkit::findInPath(PHP_OS_FAMILY === 'Windows' ? 'ssh-keygen.exe' : 'ssh-keygen') === null) {
            return false;
        }

        $process = new Process([
            'ssh-keygen',
            '-t',
            'ed25519',
            '-f',
            $privatePath,
            '-N',
            '',
            '-q',
        ]);
        $process->setTimeout(30);
        $process->run();

        return $process->isSuccessful() && is_file($privatePath) && is_file($publicPath);
    }

    public static function findNpx(): ?string
    {
        return self::findInPath(PHP_OS_FAMILY === 'Windows' ? 'npx.cmd' : 'npx')
            ?? self::findInPath(PHP_OS_FAMILY === 'Windows' ? 'npx.exe' : 'npx');
    }

    public static function findNode(): ?string
    {
        return self::findInPath(PHP_OS_FAMILY === 'Windows' ? 'node.exe' : 'node');
    }

    public static function probeTcpLatency(string $host, int $port, int $timeoutSeconds = 3): ?int
    {
        $started = microtime(true);
        $ok = self::canReachTcp($host, $port, $timeoutSeconds);

        if (!$ok) {
            return null;
        }

        return (int) round((microtime(true) - $started) * 1000);
    }

    public static function probeHttpsLatency(string $url, int $timeoutSeconds = 3): ?int
    {
        $started = microtime(true);
        $ok = self::canReachHttps($url, $timeoutSeconds);

        if (!$ok) {
            return null;
        }

        return (int) round((microtime(true) - $started) * 1000);
    }

    public static function ngrokAuthtokenConfigured(): bool
    {
        if (is_string(getenv('NGROK_AUTHTOKEN')) && trim(getenv('NGROK_AUTHTOKEN')) !== '') {
            return true;
        }

        $home = getenv('USERPROFILE') ?: getenv('HOME') ?: '';

        if ($home === '') {
            return false;
        }

        foreach ([
            $home . DIRECTORY_SEPARATOR . '.ngrok2' . DIRECTORY_SEPARATOR . 'ngrok.yml',
            $home . DIRECTORY_SEPARATOR . '.config' . DIRECTORY_SEPARATOR . 'ngrok' . DIRECTORY_SEPARATOR . 'ngrok.yml',
        ] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $contents = (string) file_get_contents($path);

            if (preg_match('/^\s*authtoken\s*:/mi', $contents) === 1) {
                return true;
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $local = getenv('LOCALAPPDATA');

            if (is_string($local) && is_file($local . DIRECTORY_SEPARATOR . 'ngrok' . DIRECTORY_SEPARATOR . 'ngrok.yml')) {
                $contents = (string) file_get_contents($local . DIRECTORY_SEPARATOR . 'ngrok' . DIRECTORY_SEPARATOR . 'ngrok.yml');

                if (preg_match('/^\s*authtoken\s*:/mi', $contents) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function findInPath(string $binaryName): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $candidate = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binaryName;

            if (is_file($candidate) && self::isRunnableBinary($candidate)) {
                return $candidate;
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $result = shell_exec('where ' . escapeshellarg($binaryName) . ' 2>NUL');

            if ($result !== null) {
                $lines = array_filter(array_map('trim', explode("\n", $result)));

                if ($lines !== []) {
                    return array_values($lines)[0];
                }
            }
        } else {
            $result = shell_exec('which ' . escapeshellarg($binaryName) . ' 2>/dev/null');

            if (is_string($result) && trim($result) !== '') {
                return trim($result);
            }
        }

        return null;
    }

    public static function isRunnableBinary(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return true;
        }

        return is_executable($path);
    }

    public static function unblockWindowsBinary(string $path): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || !is_file($path)) {
            return;
        }

        $process = new Process([
            'powershell',
            '-NoProfile',
            '-Command',
            'Unblock-File -LiteralPath ' . escapeshellarg($path),
        ]);
        $process->setTimeout(15);
        $process->run();
    }

    /**
     * Quick check that a downloaded CLI binary can actually start.
     */
    public static function probeBinaryHelp(string $binary, int $timeoutSeconds = 10): bool
    {
        if (!is_file($binary)) {
            return false;
        }

        $process = new Process([$binary, '--help']);
        $process->setTimeout($timeoutSeconds);
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        return $process->isSuccessful() || str_contains(strtolower($output), 'usage:');
    }

    public static function canReachTcp(string $host, int $port, int $timeoutSeconds = 3): bool
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    public static function canReachHttps(string $url, int $timeoutSeconds = 3): bool
    {
        $context = stream_context_create([
            'http' => [
                'method'          => 'HEAD',
                'timeout'         => $timeoutSeconds,
                'follow_location' => 0,
                'user_agent'      => 'pinoox-share/1.0',
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $headers = @get_headers($url, false, $context);

        return is_array($headers) && $headers !== [];
    }

    public static function hasCurl(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return !empty(shell_exec('where curl 2>NUL'));
        }

        return !empty(shell_exec('which curl 2>/dev/null'));
    }

    public static function downloadWithCurl(string $url, string $dest): bool
    {
        $process = new Process(['curl', '-fsSL', '--output', $dest, '-L', $url]);
        $process->setTimeout(120);
        $process->run();

        return $process->isSuccessful() && is_file($dest) && filesize($dest) > 0;
    }

    public static function downloadFile(string $url, string $dest, OutputInterface $output): bool
    {
        if (self::hasCurl()) {
            if (self::downloadWithCurl($url, $dest)) {
                return true;
            }
        }

        $context = stream_context_create([
            'http' => [
                'follow_location' => true,
                'user_agent'      => 'pinoox-share/1.0',
                'timeout'         => 120,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $data = @file_get_contents($url, false, $context);

        if ($data === false || $data === '') {
            $output->writeln('<error>Share: failed to download from ' . $url . '</error>');

            return false;
        }

        file_put_contents($dest, $data);

        return true;
    }

    public static function detectArch(): string
    {
        $machine = php_uname('m');

        if (str_contains($machine, 'arm64') || str_contains($machine, 'aarch64')) {
            return 'arm64';
        }

        if (str_contains($machine, 'armv7') || str_contains($machine, 'armhf')) {
            return 'armv7';
        }

        return 'x86_64';
    }

    /**
     * @param list<string> $patterns
     */
    public static function extractUrl(string $buffer, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $buffer, $matches)) {
                $url = isset($matches[1]) && str_starts_with($matches[1], 'https://') ? $matches[1] : $matches[0];

                return rtrim(trim($url), '/');
            }
        }

        return null;
    }

    /**
     * @param list<string> $markers
     */
    public static function bufferHasAny(string $buffer, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (str_contains($buffer, $marker)) {
                return true;
            }
        }

        return false;
    }
}
