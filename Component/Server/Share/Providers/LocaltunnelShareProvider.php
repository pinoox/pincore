<?php

namespace Pinoox\Component\Server\Share\Providers;

use Pinoox\Component\Server\Share\AbstractNpxShareProvider;
use Pinoox\Component\Server\Share\ShareSetupLevel;
use Pinoox\Component\Server\Share\ShareToolkit;

class LocaltunnelShareProvider extends AbstractNpxShareProvider
{
    public function id(): string
    {
        return 'localtunnel';
    }

    public function label(): string
    {
        return 'localtunnel';
    }

    public function hint(): string
    {
        return 'Node.js + npx · no signup';
    }

    public function signupLabel(): string
    {
        return 'none';
    }

    public function connectionGuide(): string
    {
        return implode("\n", [
            'Prerequisites: Node.js LTS + npx (https://nodejs.org/).',
            'Signup: not required — first run downloads localtunnel via npx.',
            '▸ Run: php pinoox serve --share --share-provider=localtunnel',
            '▸ Public URL looks like: https://xxxx.loca.lt (may show a reminder page on first visit).',
            '▸ Requires access to localtunnel.me and the npm registry.',
            'If it fails: install Node.js, or use pinggy / bore.',
        ]);
    }

    public function autoPriority(): int
    {
        return 20;
    }

    protected function performProbe(int $timeoutSeconds): bool
    {
        return ShareToolkit::canReachHttps('https://localtunnel.me', $timeoutSeconds);
    }

    protected function npmPackage(): string
    {
        return 'localtunnel';
    }

    protected function npxArgs(int $port): array
    {
        return ['--port', (string) $port];
    }

    protected function urlPatterns(): array
    {
        return [
            '/your url is:\s*(https:\/\/[a-z0-9\-]+\.loca\.lt)/i',
            '/(https:\/\/[a-z0-9\-]+\.loca\.lt)/i',
        ];
    }
}
