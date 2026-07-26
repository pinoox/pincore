<?php

namespace Pinoox\Component\Server;

use Pinoox\Component\Server\Share\ShareGuideRenderer;
use Pinoox\Component\Server\Share\SharePinggyScreeningBypass;
use Pinoox\Component\Server\Share\ShareProviderInterface;
use Pinoox\Component\Server\Share\ShareProviderRegistry;
use Pinoox\Component\Server\Share\ShareProviderSelector;
use Pinoox\Component\Server\Share\ShareSetupLevel;
use Pinoox\Component\Server\Share\ShareStateStore;
use Pinoox\Component\Server\Share\ShareToolkit;
use Pinoox\Component\Server\Share\ShareTunnelRequest;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Exposes a local development server via public tunnel providers.
 */
class ShareManager
{
    private ?ShareProviderInterface $activeProvider = null;

    private ?string $publicUrl = null;

    private ?int $expireSeconds = null;

    private ?float $startedAt = null;

    public function __construct(
        private readonly int $port,
        private readonly string $projectRoot,
        private readonly OutputInterface $output,
        private readonly ?string $password = null,
        private readonly ?string $expireOption = null,
        private readonly string $providerId = ShareProviderSelector::AUTO,
    ) {
        $this->expireSeconds = $this->parseExpire($expireOption);
    }

    public function providerId(): string
    {
        return $this->providerId;
    }

    public function activeProviderLabel(): ?string
    {
        return $this->activeProvider?->label();
    }

    public function activeProviderId(): ?string
    {
        return $this->activeProvider?->id();
    }

    /**
     * Start a public tunnel and return the URL (or null on failure).
     */
    public function start(): ?string
    {
        $registry = new ShareProviderRegistry($this->projectRoot, $this->output);
        $this->startedAt = microtime(true);

        /** @var list<ShareProviderInterface> $candidates */
        $candidates = $this->providerId === ShareProviderSelector::AUTO
            ? $registry->rankedForAuto()
            : [$registry->get($this->providerId)];

        if ($candidates === []) {
            $this->output->writeln('<error>Share: no tunnel providers are available on this machine.</error>');
            $this->printSetupSummary($registry);

            return null;
        }

        if ($this->providerId === ShareProviderSelector::AUTO) {
            $this->output->writeln('<fg=gray>Share: auto mode — ranking providers for your network…</>');
        } elseif (!$candidates[0]->ensureReady()) {
            ShareGuideRenderer::print($this->output, $candidates[0]);

            return null;
        }

        foreach ($candidates as $provider) {
            if ($this->providerId === ShareProviderSelector::AUTO) {
                $this->output->writeln('<fg=gray>  → trying ' . $provider->label() . '…</>');

                if (!$provider->ensureReady()) {
                    continue;
                }
            }

            $url = $provider->start($this->port);

            if ($url !== null) {
                $this->activeProvider = $provider;
                $this->publicUrl = $url;
                (new ShareStateStore($this->projectRoot))->remember($provider->id());
                ShareTunnelRequest::rememberPublicUrl($this->projectRoot, $url);

                if ($this->providerId === ShareProviderSelector::AUTO) {
                    $this->output->writeln('<info>Share: connected via ' . $provider->label() . '</info>');
                }

                if ($provider->id() === 'pinggy') {
                    SharePinggyScreeningBypass::activate($this->projectRoot);
                }

                return $url;
            }

            $provider->stop();
        }

        if ($this->providerId !== ShareProviderSelector::AUTO && isset($candidates[0])) {
            ShareGuideRenderer::print($this->output, $candidates[0]);
        }

        if ($this->providerId === ShareProviderSelector::AUTO) {
            $this->output->writeln('<error>Share: all available tunnel providers failed.</error>');
            ShareGuideRenderer::printAutoHint($this->output);
            $this->output->writeln('<comment>  Tip: php pinoox serve --share-guide=PROVIDER for setup steps.</comment>');
            $this->printSetupSummary($registry);
        }

        return null;
    }

    private function printSetupSummary(ShareProviderRegistry $registry): void
    {
        $this->output->writeln('<comment>  Available without signup (auto-install when possible):</comment>');

        foreach ($registry->all() as $provider) {
            if ($provider->setupLevel() === ShareSetupLevel::NeedsAccount) {
                continue;
            }

            $note = match ($provider->setupLevel()) {
                ShareSetupLevel::AutoInstall => $provider->isInstalled() ? 'ready' : 'downloads on use',
                ShareSetupLevel::Ready => 'ready',
                default => 'needs ' . $provider->setupLevel()->value,
            };

            $this->output->writeln('  <fg=gray>• ' . $provider->label() . ' — ' . $note . '</>');
        }

        $this->output->writeln('<comment>  Requires account: ngrok (ngrok config add-authtoken …)</comment>');
    }

    public function checkExpiry(): bool
    {
        if ($this->expireSeconds === null || $this->startedAt === null) {
            return false;
        }

        if ((microtime(true) - $this->startedAt) >= $this->expireSeconds) {
            $this->stop();

            return true;
        }

        return false;
    }

    public function stop(): void
    {
        if ($this->activeProvider?->id() === 'pinggy') {
            SharePinggyScreeningBypass::deactivate($this->projectRoot);
        }

        ShareTunnelRequest::forgetPublicUrl($this->projectRoot);
        $this->activeProvider?->stop();
        $this->activeProvider = null;
    }

    public function isRunning(): bool
    {
        return $this->activeProvider !== null && $this->activeProvider->isRunning();
    }

    public function hasDisconnected(): bool
    {
        return $this->activeProvider !== null && $this->activeProvider->hasDisconnected();
    }

    public function getPublicUrl(): ?string
    {
        return $this->publicUrl ?? $this->activeProvider?->getPublicUrl();
    }

    public function hasPassword(): bool
    {
        return $this->password !== null && $this->password !== '';
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getExpireSeconds(): ?int
    {
        return $this->expireSeconds;
    }

    public static function buildPasswordGateScript(string $actualRouter, string $password): string
    {
        $escapedPassword = var_export($password, true);
        $escapedRouter = var_export($actualRouter, true);

        return <<<PHP
<?php
// Pinoox share password gate — temporary tunnel protection
\$sharePassword = {$escapedPassword};
\$cookieName = 'pinx_share_gate';

if (isset(\$_COOKIE[\$cookieName]) && hash_equals(\$sharePassword, \$_COOKIE[\$cookieName])) {
    require {$escapedRouter};
    return false;
}

if (\$_SERVER['REQUEST_METHOD'] === 'POST' && isset(\$_POST['pinx_password'])) {
    if (hash_equals(\$sharePassword, (string) \$_POST['pinx_password'])) {
        setcookie(\$cookieName, \$sharePassword, ['expires' => time() + 3600, 'path' => '/', 'samesite' => 'Lax']);
        header('Location: ' . (\$_SERVER['REQUEST_URI'] ?? '/'));
        exit;
    }
}

http_response_code(401);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><title>Protected — Pinoox Share</title>'
   . '<meta name="viewport" content="width=device-width,initial-scale=1">'
   . '<style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f3f4f6}'
   . 'form{background:#fff;padding:2rem 2.5rem;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);min-width:300px}'
   . 'h2{margin:0 0 1.25rem;font-size:1.25rem;color:#111}'
   . 'input[type=password]{width:100%;padding:.6rem .75rem;border:1.5px solid #d1d5db;border-radius:6px;font-size:1rem;box-sizing:border-box}'
   . 'button{margin-top:1rem;width:100%;padding:.65rem;background:#2563eb;color:#fff;border:none;border-radius:6px;font-size:1rem;cursor:pointer}'
   . 'button:hover{background:#1d4ed8}.hint{margin-top:.75rem;font-size:.85rem;color:#6b7280;text-align:center}</style></head>'
   . '<body><form method="POST"><h2>🔒 Enter password to continue</h2>'
   . '<input type="password" name="pinx_password" placeholder="Password" autofocus>'
   . '<button type="submit">Enter</button>'
   . '<p class="hint">Protected by Pinoox Share</p></form></body></html>';
return true;
PHP;
    }

    public function writePasswordGateScript(string $actualRouter): string
    {
        $dir = ShareToolkit::binDir($this->projectRoot);
        $path = $dir . DIRECTORY_SEPARATOR . 'share_gate_' . md5($actualRouter) . '.php';
        file_put_contents($path, self::buildPasswordGateScript($actualRouter, (string) $this->password));

        return $path;
    }

    private function parseExpire(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^(\d+)(h|m|s)?$/i', $value, $m)) {
            $n = (int) $m[1];
            $unit = strtolower($m[2] ?? 's');

            return match ($unit) {
                'h' => $n * 3600,
                'm' => $n * 60,
                default => $n,
            };
        }

        return null;
    }
}
