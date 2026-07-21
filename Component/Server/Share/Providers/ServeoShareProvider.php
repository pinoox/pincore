<?php

namespace Pinoox\Component\Server\Share\Providers;

use Pinoox\Component\Server\Share\AbstractSshShareProvider;
use Pinoox\Component\Server\Share\ShareToolkit;

class ServeoShareProvider extends AbstractSshShareProvider
{
    /** @var list<string> */
    private const MARKETING_HOSTS = [
        'console.serveo.net',
        'www.serveo.net',
        'serveo.net',
    ];

    public function id(): string
    {
        return 'serveo';
    }

    public function label(): string
    {
        return 'Serveo';
    }

    public function hint(): string
    {
        return 'OpenSSH · SSH to serveo.net:22';
    }

    public function signupLabel(): string
    {
        return 'none';
    }

    public function connectionGuide(): string
    {
        return implode("\n", [
            'Prerequisites: OpenSSH client (ssh).',
            'Signup: not required.',
            '▸ Run: php pinoox serve --share --share-provider=serveo',
            '▸ Public URL looks like: https://random-name.serveo.net',
            '▸ Requires outbound SSH to serveo.net on port 22.',
            '⚠ Do not open console.serveo.net — that is Serveo\'s dashboard, not your site.',
            '⚠ Many networks block port 22 — prefer pinggy (443) or localhostrun if Serveo fails.',
            'If it fails: install OpenSSH Client on Windows, or try another provider.',
        ]);
    }

    public function autoPriority(): int
    {
        return 80;
    }

    protected function performProbe(int $timeoutSeconds): bool
    {
        return ShareToolkit::canReachTcp('serveo.net', 22, $timeoutSeconds);
    }

    protected function sshArgs(int $port): array
    {
        return [
            '-R', '80:127.0.0.1:' . $port,
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'ServerAliveInterval=30',
            '-o', 'ServerAliveCountMax=3',
            '-o', 'ExitOnForwardFailure=yes',
            'serveo.net',
        ];
    }

    protected function urlPatterns(): array
    {
        return [
            '/Forwarding HTTP traffic from (https:\/\/[^\s]+)/i',
            '/(https:\/\/[a-z0-9][a-z0-9\-]*\.serveo\.net)/i',
        ];
    }

    protected function readyMarkers(): array
    {
        return [
            'Forwarding HTTP traffic from',
        ];
    }

    protected function waitSeconds(): int
    {
        return 60;
    }

    protected function extractPublicUrl(string $buffer): ?string
    {
        $urls = [];

        foreach ($this->urlPatterns() as $pattern) {
            if (preg_match_all($pattern, $buffer, $matches)) {
                foreach ($matches[1] ?? $matches[0] as $match) {
                    $url = rtrim(trim((string) $match), '/');

                    if ($url !== '' && !$this->isServeoMarketingUrl($url)) {
                        $urls[] = $url;
                    }
                }
            }
        }

        if ($urls === []) {
            return null;
        }

        foreach ($urls as $url) {
            if (str_starts_with($url, 'https://')) {
                return $url;
            }
        }

        return $urls[0];
    }

    private function isServeoMarketingUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return true;
        }

        $host = strtolower($host);

        return in_array($host, self::MARKETING_HOSTS, true);
    }
}
