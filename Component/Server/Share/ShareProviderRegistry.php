<?php

namespace Pinoox\Component\Server\Share;

use Pinoox\Component\Server\Share\Providers\BoreShareProvider;
use Pinoox\Component\Server\Share\Providers\CloudflareShareProvider;
use Pinoox\Component\Server\Share\Providers\LocalhostRunShareProvider;
use Pinoox\Component\Server\Share\Providers\LocaltunnelShareProvider;
use Pinoox\Component\Server\Share\Providers\NgrokShareProvider;
use Pinoox\Component\Server\Share\Providers\PinggyShareProvider;
use Pinoox\Component\Server\Share\Providers\ServeoShareProvider;
use Pinoox\Component\Server\Share\Providers\TunnelmoleShareProvider;
use Symfony\Component\Console\Output\OutputInterface;

class ShareProviderRegistry
{
    /** @var array<string, ShareProviderInterface> */
    private array $providers;

    public function __construct(
        private readonly string $projectRoot,
        private readonly OutputInterface $output,
    ) {
        $this->providers = $this->bootProviders();
    }

    /**
     * @return array<string, ShareProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->providers);
    }

    public function get(string $id): ShareProviderInterface
    {
        $id = strtolower(trim($id));

        if (!isset($this->providers[$id])) {
            throw new \InvalidArgumentException(sprintf(
                "Unknown share provider '%s'. Available: %s",
                $id,
                implode(', ', $this->ids()),
            ));
        }

        return $this->providers[$id];
    }

    /**
     * @return list<ShareProviderInterface>
     */
    public function rankedForAuto(int $probeTimeoutSeconds = 3): array
    {
        return (new ShareAutoRanker($this->projectRoot, $this->output))->rank($this->providers, $probeTimeoutSeconds);
    }

    /**
     * @return list<array{id: string, label: string, hint: string, status: string, setup: string}>
     */
    public function describeForMenu(int $probeTimeoutSeconds = 3): array
    {
        $rows = [
            [
                'id' => 'auto',
                'label' => 'Auto',
                'signup' => 'none',
                'hint' => 'Probe network, auto-install binaries, pick best',
                'status' => 'recommended',
                'setup' => 'ready',
            ],
        ];

        foreach ($this->providers as $provider) {
            $rows[] = [
                'id' => $provider->id(),
                'label' => $provider->label(),
                'signup' => $provider->signupLabel(),
                'hint' => $provider->hint(),
                'status' => $this->statusLabel($provider, $probeTimeoutSeconds),
                'setup' => $provider->setupLevel()->value,
            ];
        }

        return $rows;
    }

    private function statusLabel(ShareProviderInterface $provider, int $probeTimeoutSeconds): string
    {
        if (!$provider->canAutoTry()) {
            return $provider->setupLevel()->value;
        }

        if ($provider->setupLevel() === ShareSetupLevel::AutoInstall && !$provider->isInstalled()) {
            return 'auto-download';
        }

        if (!$provider->isReady()) {
            return 'needs setup';
        }

        return $provider->probe($probeTimeoutSeconds) ? 'reachable' : 'blocked';
    }

    /**
     * @return array<string, ShareProviderInterface>
     */
    private function bootProviders(): array
    {
        $providers = [
            new PinggyShareProvider($this->projectRoot, $this->output),
            new ServeoShareProvider($this->projectRoot, $this->output),
            new CloudflareShareProvider($this->projectRoot, $this->output),
            new LocalhostRunShareProvider($this->projectRoot, $this->output),
            new BoreShareProvider($this->projectRoot, $this->output),
            new TunnelmoleShareProvider($this->projectRoot, $this->output),
            new NgrokShareProvider($this->projectRoot, $this->output),
            new LocaltunnelShareProvider($this->projectRoot, $this->output),
        ];

        $map = [];

        foreach ($providers as $provider) {
            $map[$provider->id()] = $provider;
        }

        return $map;
    }
}
