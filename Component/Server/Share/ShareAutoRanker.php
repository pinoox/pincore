<?php

namespace Pinoox\Component\Server\Share;

use Symfony\Component\Console\Output\OutputInterface;

final class ShareAutoRanker
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly OutputInterface $output,
    ) {
    }

    /**
     * @param array<string, ShareProviderInterface> $providers
     * @return list<ShareProviderInterface>
     */
    public function rank(array $providers, int $probeTimeoutSeconds = 3): array
    {
        $profile = new ShareNetworkProfile($this->projectRoot);
        $summary = $profile->summary();

        if ($summary !== null) {
            $this->output->writeln('<comment>Share: ' . $summary . '</comment>');
        }

        $lastId = (new ShareStateStore($this->projectRoot))->lastProviderId();
        $scored = [];

        foreach ($providers as $provider) {
            if (!$provider->canAutoTry()) {
                continue;
            }

            $score = $provider->autoPriority();
            $reachable = $provider->probe($probeTimeoutSeconds);
            $latencyMs = $provider->lastProbeLatencyMs();

            if ($reachable) {
                $score += 1000;
                $score -= min(500, (int) floor($latencyMs / 10));
            } else {
                $score -= 500;
            }

            if ($profile->prefersSshTunnels()) {
                $score += match ($provider->transportKind()) {
                    'ssh' => 120,
                    'binary' => 60,
                    default => 0,
                };
            }

            if ($profile->cloudflareFiltered() && $provider->id() === 'cloudflare') {
                $score -= 800;
            }

            if (!$profile->cloudflareFiltered() && $provider->id() === 'cloudflare' && $reachable) {
                $score += 80;
            }

            if ($provider->setupLevel() === ShareSetupLevel::Ready) {
                $score += 40;
            }

            if ($lastId !== null && $provider->id() === $lastId && $reachable) {
                $score += 200;
            }

            $scored[] = ['provider' => $provider, 'score' => $score, 'reachable' => $reachable];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(static fn (array $row): ShareProviderInterface => $row['provider'], $scored);
    }
}
