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
        $network = $primary->serveHost === '0.0.0.0' || $primary->serveHost === '[::]';
        $platformServe = $serveBinding === FrontendDevSession::SERVE_PLATFORM;
        $multipleStacks = count($sessions) > 1;
        $uniquePackages = array_values(array_unique(array_map(
            static fn (FrontendDevSession $session): string => $session->package,
            $sessions,
        )));
        $multipleApps = count($uniquePackages) > 1;
        $modeLabel = $platformServe && $multipleApps
            ? 'Platform router'
            : ($uniquePackages[0] ?? 'app');
        $contextNames = $multipleStacks
            ? array_values(array_filter(
                array_map(static fn (array $target): string => (string) ($target['context'] ?? ''), $stackTargets),
                static fn (string $name): bool => trim($name) !== '',
            ))
            : [];
        $appUrls = self::appUrlsByPackage($sessions, $withServe, $serveBinding);

        $io->writeln('');
        $io->title('Frontend development');

        $summary = [
            'Mode' => $platformServe ? 'Multi-app (platform)' : 'Single app',
            'Package' => $multipleApps ? implode(', ', $uniquePackages) : $modeLabel,
        ];

        if ($withServe && !$multipleApps) {
            $summary['PHP URL'] = $appUrls[$uniquePackages[0]] ?? rtrim($primary->phpOrigin(), '/');
        }

        if ($multipleStacks) {
            $summary['Vite'] = count($sessions) . ' dev servers';
        } else {
            $summary['Vite port'] = $sessions[0]->viteDevPortLabel();
        }

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

        if ($multipleStacks) {
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
        }

        if ($withServe) {
            if ($multipleApps) {
                $rows = [];

                foreach ($uniquePackages as $package) {
                    $rows[] = [
                        $package,
                        $appUrls[$package] ?? rtrim($primary->phpOrigin(), '/'),
                    ];
                }

                $io->section('Open in browser');
                $io->table(['App', 'URL'], $rows);

                if ($multipleStacks) {
                    $io->writeln('  <fg=gray>HMR follows the route theme for each app</>');
                }
            } else {
                $url = $appUrls[$uniquePackages[0]] ?? rtrim($primary->phpOrigin(), '/');
                $hint = $multipleStacks
                    ? 'in your browser (HMR follows the route theme)'
                    : 'in your browser';
                $io->writeln('  <fg=gray>Open</>  ' . $url . '  <fg=gray>' . $hint . '</>');
            }
        }

        $io->writeln('  <fg=gray>Vite ports are internal — do not open them in the browser</>');
        $io->writeln('  <fg=gray>Stop</>   Ctrl+C');
        $io->writeln('');
    }

    /**
     * @param list<FrontendDevSession> $sessions
     * @return array<string, string>
     */
    private static function appUrlsByPackage(array $sessions, bool $withServe, string $serveBinding): array
    {
        $urls = [];
        $platformServe = $serveBinding === FrontendDevSession::SERVE_PLATFORM;

        foreach ($sessions as $session) {
            $package = $session->package;

            if (isset($urls[$package])) {
                continue;
            }

            if (!$withServe) {
                continue;
            }

            if ($platformServe || $session->platformServe) {
                $routerUrls = FrontendDevSession::appRouterUrlsForPackage(
                    $package,
                    $session->serveHost,
                    $session->servePort,
                    $session->serveDomain,
                );
                $urls[$package] = $routerUrls[0]
                    ?? FrontendDevSession::resolvePublicAppUrl(
                        $package,
                        $session->serveHost,
                        $session->servePort,
                        $session->serveDomain,
                    );

                continue;
            }

            $displayUrls = $session->displayAppUrls();
            $urls[$package] = $displayUrls[0] ?? rtrim($session->phpOrigin(), '/');
        }

        return $urls;
    }
}
