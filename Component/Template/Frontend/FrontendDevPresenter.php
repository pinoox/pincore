<?php

namespace Pinoox\Component\Template\Frontend;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared CLI banner for theme frontend dev (single stack or multi-context).
 */
final class FrontendDevPresenter
{
    /**
     * @param list<array{context?: ?string, theme?: string}> $stackTargets
     * @param list<FrontendDevSession> $sessions
     */
    public static function render(
        SymfonyStyle $io,
        array $stackTargets,
        array $sessions,
        string $serveBinding,
        bool $withServe = true,
    ): void {
        if ($sessions === []) {
            return;
        }

        $primary = $sessions[0];
        $origin = rtrim($primary->phpOrigin(), '/');
        $network = $primary->serveHost === '0.0.0.0' || $primary->serveHost === '[::]';
        $platformServe = $serveBinding === FrontendDevSession::SERVE_PLATFORM;
        $packageLabels = array_values(array_unique(array_map(
            static fn (FrontendDevSession $session): string => $session->package,
            $sessions,
        )));
        $modeLabel = $platformServe ? 'Platform router' : ($packageLabels[0] ?? 'app');
        $contextNames = array_values(array_filter(
            array_map(static fn (array $target): string => (string) ($target['context'] ?? ''), $stackTargets),
            static fn (string $name): bool => trim($name) !== '',
        ));

        $io->writeln('');
        $io->title('Frontend development');

        $summary = $withServe
            ? [
                'Mode' => $platformServe ? 'Multi-app (platform)' : 'Single app',
                'Package' => $modeLabel,
                'PHP URL' => $origin,
                'Vite' => count($sessions) . ' dev server' . (count($sessions) === 1 ? '' : 's'),
            ]
            : [
                'Mode' => 'Vite only',
                'Package' => $modeLabel,
                'Vite' => count($sessions) . ' dev server' . (count($sessions) === 1 ? '' : 's'),
            ];

        if ($contextNames !== []) {
            $summary['Contexts'] = implode(', ', $contextNames);
        }

        $io->definitionList(...array_map(
            static fn (string $label, string $value): array => [$label => $value],
            array_keys($summary),
            array_values($summary),
        ));

        if ($withServe && $network) {
            $lan = FrontendDevSession::detectLanIp();

            if ($lan !== null) {
                $io->writeln('  <fg=gray>LAN</>  http://' . $lan . ':' . $primary->servePort);
            }
        }

        $rows = [];

        foreach ($sessions as $index => $session) {
            $context = trim((string) ($stackTargets[$index]['context'] ?? ''));
            $themeFolder = trim((string) ($stackTargets[$index]['theme'] ?? ''));
            $label = $context !== ''
                ? $context
                : ThemeFrontendDevTarget::stackLabel($session->package, null);

            $rows[] = [
                $label,
                $themeFolder !== '' ? $themeFolder : '—',
                $session->viteDevPortLabel(),
            ];
        }

        $io->section('Vite stacks');
        $io->table(['Context', 'Theme', 'Vite port'], $rows);

        if ($withServe) {
            $io->writeln('  <fg=gray>Open</>  ' . $origin . '  <fg=gray>in your browser (HMR follows the route theme)</>');
        }

        $io->writeln('  <fg=gray>Vite ports are internal — do not open them in the browser</>');
        $io->writeln('  <fg=gray>Stop</>   Ctrl+C');
        $io->writeln('');
    }
}
