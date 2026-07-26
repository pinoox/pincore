<?php

namespace Pinoox\Component\Server\Share\Providers;

use Pinoox\Component\Server\Share\AbstractNpxShareProvider;
use Pinoox\Component\Server\Share\ShareToolkit;

class TunnelmoleShareProvider extends AbstractNpxShareProvider
{
    public function id(): string
    {
        return 'tunnelmole';
    }

    public function label(): string
    {
        return 'Tunnelmole';
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
            'Signup: not required — first run downloads tunnelmole via npx.',
            '▸ Run: php pinoox serve --share --share-provider=tunnelmole',
            '▸ Allow network access on first run while npx downloads the package.',
            '▸ Requires access to tunnelmole.com and the npm registry.',
            'If it fails: install Node.js, or use pinggy / bore (no Node.js needed).',
        ]);
    }

    public function autoPriority(): int
    {
        return 40;
    }

    protected function performProbe(int $timeoutSeconds): bool
    {
        return ShareToolkit::canReachHttps('https://tunnelmole.com', $timeoutSeconds);
    }

    protected function npmPackage(): string
    {
        return 'tunnelmole';
    }

    protected function npxArgs(int $port): array
    {
        return [(string) $port];
    }

    protected function urlPatterns(): array
    {
        return [
            '/(https:\/\/[a-z0-9\-]+\.tunnelmole\.net)/i',
            '/Your URL is:\s*(https:\/\/[^\s]+)/i',
        ];
    }
}
