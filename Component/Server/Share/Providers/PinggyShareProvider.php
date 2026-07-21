<?php

namespace Pinoox\Component\Server\Share\Providers;

use Pinoox\Component\Server\Share\AbstractSshShareProvider;
use Pinoox\Component\Server\Share\ShareGuideRenderer;
use Pinoox\Component\Server\Share\ShareSetupLevel;
use Pinoox\Component\Server\Share\ShareToolkit;

class PinggyShareProvider extends AbstractSshShareProvider
{
    public function id(): string
    {
        return 'pinggy';
    }

    public function label(): string
    {
        return 'Pinggy';
    }

    public function hint(): string
    {
        return 'OpenSSH + auto SSH key · optional token in .env';
    }

    public function signupLabel(): string
    {
        return 'optional';
    }

    public function connectionGuide(): string
    {
        return implode("\n", [
            'Prerequisites: OpenSSH client (ssh + ssh-keygen).',
            'Signup: optional — free tier works without account; a token gives stable URLs.',
            '▸ Pinoox auto-creates .pinoox/bin/pinggy_ed25519 (no passphrase).',
            '▸ Optional .env: PINGGY_TOKEN=your_token  (from https://pinggy.io → SSH command token)',
            '▸ Run: php pinoox serve --share --share-provider=pinggy',
            '▸ Uses SSH on port 443 — often works on restricted networks.',
            '▸ Free tunnels show a one-time browser warning — Pinoox auto-bypasses asset screening.',
            '⚠ Click Enter site on the first Pinggy warning page, then the app reloads without broken JS/CSS.',
            '⚠ Do not enter a password manually — the auto-generated SSH key is used instead.',
            'If it fails: add PINGGY_TOKEN to .env, or try localhostrun / bore.',
        ]);
    }

    public function autoPriority(): int
    {
        return 90;
    }

    public function setupLevel(): ShareSetupLevel
    {
        if (ShareToolkit::findSsh() === null) {
            return ShareSetupLevel::NeedsTool;
        }

        return ShareSetupLevel::Ready;
    }

    public function ensureReady(): bool
    {
        if (!parent::ensureReady()) {
            return false;
        }

        if ($this->pinggyKeyPath() !== null) {
            return true;
        }

        $this->output->writeln('<comment>Share: creating Pinggy SSH key (one-time, no passphrase)…</comment>');

        if (ShareToolkit::ensureEd25519KeyPair($this->pinggyKeyPrivatePath())) {
            $this->output->writeln('<info>Share: Pinggy SSH key ready at ' . $this->pinggyKeyPrivatePath() . '</info>');

            return true;
        }

        $this->output->writeln('<error>Share: could not create Pinggy SSH key.</error>');
        $this->output->writeln('<comment>  Run manually: ssh-keygen -t ed25519 -f "' . $this->pinggyKeyPrivatePath() . '" -N ""</comment>');

        return false;
    }

    protected function performProbe(int $timeoutSeconds): bool
    {
        return ShareToolkit::canReachTcp('free.pinggy.io', 443, $timeoutSeconds);
    }

    protected function sshArgs(int $port): array
    {
        $key = $this->pinggyKeyPath();

        if ($key === null) {
            return [];
        }

        $args = [
            '-p', '443',
            '-i', $key,
            '-R0:127.0.0.1:' . $port,
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'UserKnownHostsFile=' . $this->pinggyKnownHostsPath(),
            '-o', 'BatchMode=yes',
            '-o', 'PasswordAuthentication=no',
            '-o', 'IdentitiesOnly=yes',
            '-o', 'ServerAliveInterval=30',
            '-o', 'ServerAliveCountMax=3',
            '-t',
            $this->pinggyTarget(),
        ];

        return $args;
    }

    protected function urlPatterns(): array
    {
        return [
            '/(https:\/\/[a-z0-9][a-z0-9\-]*\.run\.pinggy-free\.link)/i',
            '/(https:\/\/[a-z0-9][a-z0-9\-]*\.free\.pinggy\.net)/i',
            '/(https:\/\/[a-z0-9][a-z0-9\-]*\.pinggy-free\.link)/i',
            '/(https:\/\/[a-z0-9][a-z0-9\-]*\.a\.free\.pinggy\.(?:io|link))/i',
            '/(http:\/\/[a-z0-9][a-z0-9\-]*\.run\.pinggy-free\.link)/i',
            '/(http:\/\/[a-z0-9][a-z0-9\-]*\.free\.pinggy\.net)/i',
            '/(http:\/\/[a-z0-9][a-z0-9\-]*\.pinggy-free\.link)/i',
        ];
    }

    protected function readyMarkers(): array
    {
        return [
            'pinggy-free.link',
            'free.pinggy.net',
        ];
    }

    protected function extractPublicUrl(string $buffer): ?string
    {
        $urls = [];

        foreach ($this->urlPatterns() as $pattern) {
            if (preg_match_all($pattern, $buffer, $matches)) {
                foreach ($matches[1] ?? $matches[0] as $match) {
                    $url = rtrim(trim((string) $match), '/');

                    if ($url !== '' && !$this->isPinggyMarketingUrl($url)) {
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

    private function isPinggyMarketingUrl(string $url): bool
    {
        return (bool) preg_match('/\/\/(?:dashboard|www|api)\.pinggy\.|\/\/pinggy\.io\/?$/i', $url);
    }

    protected function connectionErrorMarkers(): array
    {
        return array_merge(parent::connectionErrorMarkers(), [
            'Permission denied (publickey',
            'Authentication failed',
        ]);
    }

    protected function emitConnectionError(string $buffer): void
    {
        $this->output->writeln('<error>Share: Pinggy could not connect.</error>');
        ShareGuideRenderer::print($this->output, $this);
        $this->writeTail($buffer);
    }

    protected function waitSeconds(): int
    {
        return 60;
    }

    private function pinggyKeyPrivatePath(): string
    {
        return ShareToolkit::binDir($this->projectRoot) . DIRECTORY_SEPARATOR . 'pinggy_ed25519';
    }

    private function pinggyKnownHostsPath(): string
    {
        return ShareToolkit::binDir($this->projectRoot) . DIRECTORY_SEPARATOR . 'pinggy_known_hosts';
    }

    private function pinggyKeyPath(): ?string
    {
        $path = $this->pinggyKeyPrivatePath();

        return is_file($path) ? $path : null;
    }

    private function pinggyTarget(): string
    {
        $token = trim((string) _env('PINGGY_TOKEN', ''));

        if ($token === '') {
            $token = trim((string) _env('SERVER_SHARE_PINGGY_TOKEN', ''));
        }

        if ($token !== '') {
            return $token . '@free.pinggy.io';
        }

        return 'free.pinggy.io';
    }
}
