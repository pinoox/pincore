<?php

namespace Pinoox\Component\Server\Share\Providers;

use Pinoox\Component\Server\Share\AbstractSshShareProvider;
use Pinoox\Component\Server\Share\ShareToolkit;

class LocalhostRunShareProvider extends AbstractSshShareProvider
{
    public function id(): string
    {
        return 'localhostrun';
    }

    public function label(): string
    {
        return 'localhost.run';
    }

    public function hint(): string
    {
        return 'OpenSSH · SSH to localhost.run:22';
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
            '▸ Run: php pinoox serve --share --share-provider=localhostrun',
            '▸ Public URL looks like: https://xxxx.lhr.life',
            '▸ Requires outbound SSH to localhost.run on port 22.',
            '⚠ Good fallback when Cloudflare or HTTP tunnels are blocked on your network.',
            'If it fails: port 22 may be blocked — try pinggy (443) or bore.',
        ]);
    }

    public function autoPriority(): int
    {
        return 60;
    }

    protected function performProbe(int $timeoutSeconds): bool
    {
        return ShareToolkit::canReachTcp('localhost.run', 22, $timeoutSeconds);
    }

    protected function sshArgs(int $port): array
    {
        return [
            '-R', '80:127.0.0.1:' . $port,
            '-R', '443:127.0.0.1:' . $port,
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'ServerAliveInterval=30',
            '-o', 'ServerAliveCountMax=3',
            'localhost.run',
        ];
    }

    protected function urlPatterns(): array
    {
        return [
            '/(https:\/\/[a-z0-9]+\.lhr\.life)/i',
            '/(https:\/\/[a-z0-9\-]+\.localhost\.run)/i',
        ];
    }

    protected function waitSeconds(): int
    {
        return 60;
    }
}
