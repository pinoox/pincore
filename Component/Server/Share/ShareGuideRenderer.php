<?php

namespace Pinoox\Component\Server\Share;

use Symfony\Component\Console\Output\OutputInterface;

final class ShareGuideRenderer
{
    public static function print(OutputInterface $output, ShareProviderInterface $provider): void
    {
        $output->writeln('');
        $output->writeln('<info>── Share guide: ' . $provider->label() . ' ──</info>');
        $output->writeln('<fg=gray>  Signup: ' . $provider->signupLabel() . ' · Setup: ' . $provider->setupLevel()->value . '</>');

        foreach (self::lines($provider->connectionGuide()) as $line) {
            if (str_starts_with($line, '▸')) {
                $output->writeln('  <comment>' . $line . '</comment>');
            } elseif (str_starts_with($line, '⚠')) {
                $output->writeln('  <fg=yellow>' . $line . '</>');
            } else {
                $output->writeln('  ' . $line);
            }
        }

        $output->writeln('');
    }

    public static function printCatalog(OutputInterface $output, ShareProviderRegistry $registry): void
    {
        $output->writeln('');
        $output->writeln('<info>── Share providers ──</info>');
        $output->writeln('  <fg=gray>Use --share-guide=PROVIDER for full setup steps (e.g. pinggy, bore, ngrok).</>');
        $output->writeln('');

        foreach ($registry->describeForMenu(0) as $choice) {
            if ($choice['id'] === 'auto') {
                continue;
            }

            $output->writeln(sprintf(
                '  <comment>%s</comment> · signup: %s · %s',
                $choice['label'],
                $choice['signup'],
                $choice['hint'],
            ));
        }

        $output->writeln('');
        self::printAutoHint($output);
    }

    public static function printAutoHint(OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('<info>── Share guide: Auto ──</info>');
        $output->writeln('  Probes your network, auto-downloads tools when needed,');
        $output->writeln('  then tries providers by reachability with this fallback order:');
        $output->writeln('  <comment>pinggy → serveo → cloudflare → localhostrun → bore → tunnelmole → ngrok → localtunnel</comment>');
        $output->writeln('  <comment>▸ Recommended when unsure — no manual config.</comment>');
        $output->writeln('  <comment>▸ Set SERVER_SHARE_PROVIDER=auto in .env to skip the menu.</comment>');
        $output->writeln('  <fg=yellow>⚠ Behind a firewall, VPN, or corporate proxy? Auto picks a provider that fits your network.</>');
        $output->writeln('  <fg=yellow>⚠ If Auto fails, pick one explicitly: php pinoox serve --share-guide=PROVIDER</>');
        $output->writeln('');
    }

    /**
     * @return list<string>
     */
    private static function lines(string $guide): array
    {
        $lines = [];

        foreach (preg_split('/\r\n|\r|\n/', $guide) as $line) {
            $line = trim($line);

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
