<?php

namespace Pinoox\Component\Server\Share\Providers;

use Pinoox\Component\Server\Share\AbstractBinaryShareProvider;
use Pinoox\Component\Server\Share\ShareGuideRenderer;
use Pinoox\Component\Server\Share\ShareToolkit;

class BoreShareProvider extends AbstractBinaryShareProvider
{
    private const VERSION = 'v0.6.0';

    private const DOWNLOAD_URLS = [
        'Windows' => [
            'x86_64' => 'https://github.com/ekzhang/bore/releases/download/v0.6.0/bore-v0.6.0-x86_64-pc-windows-msvc.zip',
            'arm64'  => 'https://github.com/ekzhang/bore/releases/download/v0.6.0/bore-v0.6.0-aarch64-pc-windows-msvc.zip',
        ],
        'Linux' => [
            'x86_64' => 'https://github.com/ekzhang/bore/releases/download/v0.6.0/bore-v0.6.0-x86_64-unknown-linux-musl.tar.gz',
            'arm64'  => 'https://github.com/ekzhang/bore/releases/download/v0.6.0/bore-v0.6.0-aarch64-unknown-linux-musl.tar.gz',
            'armv7'  => 'https://github.com/ekzhang/bore/releases/download/v0.6.0/bore-v0.6.0-armv7-unknown-linux-musleabihf.tar.gz',
        ],
        'Darwin' => [
            'x86_64' => 'https://github.com/ekzhang/bore/releases/download/v0.6.0/bore-v0.6.0-x86_64-apple-darwin.tar.gz',
            'arm64'  => 'https://github.com/ekzhang/bore/releases/download/v0.6.0/bore-v0.6.0-aarch64-apple-darwin.tar.gz',
        ],
    ];

    public function id(): string
    {
        return 'bore';
    }

    public function label(): string
    {
        return 'Bore';
    }

    public function hint(): string
    {
        return 'Auto-downloads bore · URL is http://bore.pub:PORT';
    }

    public function signupLabel(): string
    {
        return 'none';
    }

    public function connectionGuide(): string
    {
        return implode("\n", [
            'Prerequisites: internet (bore binary downloads to .pinoox/bin/ on first run).',
            'Signup: not required.',
            '▸ Run: php pinoox serve --share --share-provider=bore',
            '▸ Public URL looks like: http://bore.pub:12345 (HTTP, not HTTPS).',
            '▸ Requires outbound TCP to bore.pub:7835 (control port).',
            '⚠ Windows: Defender may block bore.exe — allow .pinoox/bin/bore.exe or run: winget install ekzhang.bore',
            'If it fails: bore.pub may be blocked on your network — try pinggy or localhostrun.',
        ]);
    }

    public function autoPriority(): int
    {
        return 50;
    }

    public function ensureReady(): bool
    {
        if (!parent::ensureReady()) {
            return false;
        }

        $binary = $this->resolveBinary();

        if ($binary === null) {
            return false;
        }

        ShareToolkit::unblockWindowsBinary($binary);

        if (ShareToolkit::probeBinaryHelp($binary)) {
            return true;
        }

        $this->output->writeln('<error>Share: bore.exe exists but Windows blocked it from running.</error>');
        $this->output->writeln('<comment>  Delete .pinoox/bin/bore.exe, allow it in Defender, or install: winget install ekzhang.bore</comment>');

        return false;
    }

    protected function performProbe(int $timeoutSeconds): bool
    {
        return ShareToolkit::canReachTcp('bore.pub', 7835, $timeoutSeconds);
    }

    protected function binaryFileName(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'bore.exe' : 'bore';
    }

    protected function archiveExtractedBinaryName(): string
    {
        return $this->binaryFileName();
    }

    protected function downloadUrl(): ?string
    {
        $map = self::DOWNLOAD_URLS[PHP_OS_FAMILY] ?? null;

        if ($map === null) {
            return null;
        }

        $arch = ShareToolkit::detectArch();

        return $map[$arch] ?? $map['x86_64'] ?? null;
    }

    protected function needsArchiveExtract(): bool
    {
        $url = $this->downloadUrl();

        return is_string($url) && (str_ends_with($url, '.zip') || str_ends_with($url, '.tar.gz'));
    }

    /**
     * @return list<string>
     */
    protected function buildBinaryCommand(string $binary, int $port): array
    {
        return [
            $binary,
            'local',
            (string) $port,
            '--local-host',
            '127.0.0.1',
            '--to',
            'bore.pub',
        ];
    }

    protected function urlPatterns(): array
    {
        return [
            '/listening at (https?:\/\/bore\.pub:\d+)/i',
            '/(https?:\/\/bore\.pub:\d+)/i',
        ];
    }

    protected function readyMarkers(): array
    {
        return [
            'listening at bore.pub:',
            'listening at bore.pub',
        ];
    }

    protected function waitSeconds(): int
    {
        return 60;
    }

    protected function connectionErrorMarkers(): array
    {
        return array_merge(parent::connectionErrorMarkers(), [
            'could not connect to bore.pub',
            'server error:',
            'cannot execute the specified program',
            'contains a virus or potentially unwanted software',
        ]);
    }

    protected function extractPublicUrl(string $buffer): ?string
    {
        $url = parent::extractPublicUrl($buffer);

        if ($url !== null) {
            return $url;
        }

        if (preg_match('/listening at\s+(?:https?:\/\/)?([a-z0-9.-]+):(\d+)/i', $buffer, $matches) === 1) {
            return 'http://' . $matches[1] . ':' . $matches[2];
        }

        return null;
    }

    protected function emitStartFailure(string $buffer): void
    {
        if ($this->process !== null) {
            $buffer .= $this->process->getOutput() . $this->process->getErrorOutput();
        }

        $this->output->writeln('<error>Share: Bore exited without a public URL.</error>');

        if (str_contains(strtolower($buffer), 'cannot execute the specified program')
            || str_contains(strtolower($buffer), 'potentially unwanted software')) {
            $this->output->writeln('<comment>  Windows blocked bore.exe (Defender/SmartScreen).</comment>');
            $this->output->writeln('<comment>  Allow .pinoox/bin/bore.exe, delete it and retry, or: winget install ekzhang.bore</comment>');
        } elseif (str_contains(strtolower($buffer), 'could not connect to bore.pub')) {
            $this->output->writeln('<comment>  Cannot reach bore.pub:7835 — your network or firewall may block it.</comment>');
            $this->output->writeln('<comment>  Try: php pinoox serve --share --share-provider=pinggy</comment>');
        }

        ShareGuideRenderer::print($this->output, $this);
        $this->writeTail($buffer);
    }
}
