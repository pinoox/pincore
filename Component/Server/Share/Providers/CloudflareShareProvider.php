<?php

namespace Pinoox\Component\Server\Share\Providers;

use Pinoox\Component\Server\Share\AbstractBinaryShareProvider;
use Pinoox\Component\Server\Share\ShareToolkit;

class CloudflareShareProvider extends AbstractBinaryShareProvider
{
    private const CLOUDFLARED_URLS = [
        'Windows' => [
            'x86_64' => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe',
            'arm64'  => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-arm64.exe',
        ],
        'Linux' => [
            'x86_64' => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64',
            'arm64'  => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-arm64',
            'armv7'  => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-arm',
        ],
        'Darwin' => [
            'x86_64' => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-darwin-amd64.tgz',
            'arm64'  => 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-darwin-arm64.tgz',
        ],
    ];

    public function id(): string
    {
        return 'cloudflare';
    }

    public function label(): string
    {
        return 'Cloudflare';
    }

    public function hint(): string
    {
        return 'Auto-downloads cloudflared · no signup';
    }

    public function signupLabel(): string
    {
        return 'none';
    }

    public function connectionGuide(): string
    {
        return implode("\n", [
            'Prerequisites: internet (cloudflared downloads to .pinoox/bin/ on first run).',
            'Signup: not required.',
            '▸ Run: php pinoox serve --share --share-provider=cloudflare',
            '▸ Requires outbound HTTPS to api.trycloudflare.com.',
            '⚠ Firewalls, VPNs, and corporate proxies may block Cloudflare — use Auto or another provider.',
            'If it fails: allow cloudflared through your firewall or try --share-provider=pinggy.',
        ]);
    }

    public function autoPriority(): int
    {
        return 70;
    }

    protected function performProbe(int $timeoutSeconds): bool
    {
        return ShareToolkit::canReachHttps('https://api.trycloudflare.com/tunnel', $timeoutSeconds);
    }

    protected function binaryFileName(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'cloudflared.exe' : 'cloudflared';
    }

    protected function downloadUrl(): ?string
    {
        $map = self::CLOUDFLARED_URLS[PHP_OS_FAMILY] ?? null;

        if ($map === null) {
            return null;
        }

        $arch = ShareToolkit::detectArch();

        return $map[$arch] ?? $map['x86_64'] ?? null;
    }

    protected function needsArchiveExtract(): bool
    {
        $url = $this->downloadUrl();

        return is_string($url) && str_ends_with($url, '.tgz');
    }

    /**
     * @return list<string>
     */
    protected function buildBinaryCommand(string $binary, int $port): array
    {
        return [$binary, 'tunnel', '--url', 'http://127.0.0.1:' . $port, '--protocol', 'http2'];
    }

    protected function urlPatterns(): array
    {
        return [
            '/\|\s+(https:\/\/[a-z0-9][a-z0-9\-]+\.trycloudflare\.com)\s+\|/',
            '/created[^\n]*?(https:\/\/[a-z0-9][a-z0-9\-]+\.trycloudflare\.com)/i',
            '/\bINF\b.*?(https:\/\/[a-z0-9][a-z0-9\-]+\.trycloudflare\.com)(?!\S)/i',
            '/https:\/\/(?!api\b)[a-z0-9][a-z0-9\-]+\.trycloudflare\.com(?=[^\w])/',
        ];
    }

    protected function readyMarkers(): array
    {
        return ['Registered tunnel connection'];
    }

    protected function connectionErrorMarkers(): array
    {
        return array_merge(parent::connectionErrorMarkers(), [
            'failed to request quick Tunnel',
            'Unable to reach the Cloudflare API',
        ]);
    }

    protected function emitConnectionError(string $buffer): void
    {
        $this->output->writeln('<error>Share: Cloudflare tunnel could not connect.</error>');
        $this->output->writeln('<comment>  Cloudflare may be filtered — try Auto or --share-provider=pinggy.</comment>');
        $this->writeTail($buffer);
    }
}
