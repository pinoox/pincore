<?php

namespace Pinoox\Component\Deps;

use Pinoox\Component\Package\AppManifest;
use Pinoox\Component\Template\Frontend\ThemeFrontend;
use Pinoox\Component\Template\Frontend\ThemeFrontendDevTarget;
use Pinoox\Component\Template\Theme\ThemeContextRegistry;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Support\SystemConfig;

final class DependencyScanner
{
    public function projectRoot(): string
    {
        return SystemConfig::rootPath();
    }

    public function appsPath(): string
    {
        return SystemConfig::path('apps');
    }

    /**
     * @return list<DependencyTarget>
     */
    public function discover(
        string $scope = 'all',
        ?string $themeName = null,
        bool $allThemes = false,
        ?string $typeFilter = null,
    ): array {
        $targets = [];

        if ($scope === 'all' || $scope === 'platform') {
            $targets = array_merge($targets, $this->platformTargets($typeFilter));
        }

        if ($scope === 'all') {
            foreach (AppEngine::all() as $package => $manager) {
                if (!$manager->exists()) {
                    continue;
                }

                $targets = array_merge(
                    $targets,
                    $this->appTargets((string) $package, $themeName, $allThemes, $typeFilter),
                );
            }
        } elseif ($scope !== 'platform') {
            $targets = array_merge(
                $targets,
                $this->appTargets($scope, $themeName, $allThemes, $typeFilter),
            );
        }

        return $this->uniqueTargets($targets);
    }

    /**
     * @return list<DependencyTarget>
     */
    private function platformTargets(?string $typeFilter): array
    {
        if ($typeFilter !== null && $typeFilter !== 'composer') {
            return [];
        }

        $root = $this->projectRoot();

        if (!is_file($root . '/composer.json')) {
            return [];
        }

        return [
            new DependencyTarget(
                type: 'composer',
                scope: 'platform',
                path: $root,
                label: 'Project root (composer)',
            ),
        ];
    }

    /**
     * @return list<DependencyTarget>
     */
    private function appTargets(
        string $package,
        ?string $themeName,
        bool $allThemes,
        ?string $typeFilter,
    ): array {
        if (!AppEngine::exists($package)) {
            return [];
        }

        $targets = [];
        $appPath = rtrim(str_replace('\\', '/', AppEngine::path($package)), '/');

        if (($typeFilter === null || $typeFilter === 'composer') && is_file($appPath . '/composer.json')) {
            $targets[] = new DependencyTarget(
                type: 'composer',
                scope: $package,
                path: $appPath,
                label: $package . ' (composer)',
            );
        }

        if ($typeFilter === null || $typeFilter === 'npm') {
            foreach ($this->themePaths($package, $themeName, $allThemes) as $themeMeta) {
                $themePath = $themeMeta['path'];

                if (!is_file($themePath . '/package.json')) {
                    continue;
                }

                $targets[] = new DependencyTarget(
                    type: 'npm',
                    scope: $package,
                    path: $themePath,
                    label: $themeMeta['label'],
                );
            }
        }

        return $targets;
    }

    /**
     * @return list<array{path: string, label: string}>
     */
    private function themePaths(string $package, ?string $themeName, bool $allThemes): array
    {
        $appPath = rtrim(str_replace('\\', '/', AppEngine::path($package)), '/');
        $themesRoot = $appPath . '/theme';
        $config = AppManifest::load($package);
        $hasContexts = ThemeContextRegistry::hasContexts($config);

        if ($allThemes || ($themeName !== null && ThemeFrontendDevTarget::isAllContexts($themeName))) {
            if ($hasContexts) {
                return $this->contextThemeEntries($package, $config);
            }

            return $this->folderThemeEntries($package, $this->discoverThemeDirectories($themesRoot));
        }

        if ($themeName !== null && $themeName !== '') {
            if ($hasContexts && in_array($themeName, ThemeContextRegistry::names($config), true)) {
                $resolved = ThemeFrontendDevTarget::resolve($package, $themeName);
                $folder = $resolved['theme'];
                $context = $resolved['context'];

                return [[
                    'path' => rtrim($themesRoot . '/' . $folder, '/'),
                    'label' => $this->npmTargetLabel($package, $folder, $context),
                ]];
            }

            $folder = $themeName;

            if ($hasContexts) {
                foreach (ThemeContextRegistry::names($config) as $context) {
                    $ctx = ThemeContextRegistry::context($config, $context);
                    $ctxTheme = $ctx['theme'] ?? null;

                    if (is_string($ctxTheme) && trim($ctxTheme) === $folder) {
                        return [[
                            'path' => rtrim($themesRoot . '/' . $folder, '/'),
                            'label' => $this->npmTargetLabel($package, $folder, $context),
                        ]];
                    }
                }
            }

            return [[
                'path' => rtrim($themesRoot . '/' . $folder, '/'),
                'label' => $this->npmTargetLabel($package, $folder, null),
            ]];
        }

        if ($hasContexts) {
            $defaultContext = ThemeFrontendDevTarget::defaultChoice($package);
            $resolved = ThemeFrontendDevTarget::resolve($package, $defaultContext);

            return [[
                'path' => rtrim($themesRoot . '/' . $resolved['theme'], '/'),
                'label' => $this->npmTargetLabel($package, $resolved['theme'], $resolved['context']),
            ]];
        }

        $theme = (string) AppEngine::config($package)->get('theme', 'default');

        return [[
            'path' => rtrim($themesRoot . '/' . $theme, '/'),
            'label' => $this->npmTargetLabel($package, $theme, null),
        ]];
    }

    /**
     * @param array<string, mixed> $config
     * @return list<array{path: string, label: string}>
     */
    private function contextThemeEntries(string $package, array $config): array
    {
        $entries = [];

        foreach (ThemeFrontendDevTarget::installBuildTargetsForPackage($package) as $target) {
            $entries[] = [
                'path' => rtrim(str_replace('\\', '/', AppEngine::path($package) . '/theme/' . $target['theme']), '/'),
                'label' => $this->npmTargetLabel($package, $target['theme'], $target['context']),
            ];
        }

        return $entries;
    }

    /**
     * @param list<string> $paths
     * @return list<array{path: string, label: string}>
     */
    private function folderThemeEntries(string $package, array $paths): array
    {
        $entries = [];

        foreach ($paths as $path) {
            $folder = basename($path);
            $entries[] = [
                'path' => $path,
                'label' => $this->npmTargetLabel($package, $folder, null),
            ];
        }

        return $entries;
    }

    private function npmTargetLabel(string $package, string $themeFolder, ?string $context): string
    {
        if ($context !== null && $context !== '') {
            return $package . ' / context:' . $context . ' (theme/' . $themeFolder . ', npm)';
        }

        $details = ThemeFrontend::listThemeFolders($package)[$themeFolder] ?? $themeFolder;

        return $package . ' / theme/' . $themeFolder . ' (' . $details . ', npm)';
    }

    /**
     * @return list<string>
     */
    private function discoverThemeDirectories(string $themesRoot): array
    {
        if (!is_dir($themesRoot)) {
            return [];
        }

        $paths = [];

        foreach (scandir($themesRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $themesRoot . '/' . $entry;
            if (is_dir($path)) {
                $paths[] = rtrim(str_replace('\\', '/', $path), '/');
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * @param list<DependencyTarget> $targets
     * @return list<DependencyTarget>
     */
    private function uniqueTargets(array $targets): array
    {
        $seen = [];
        $unique = [];

        foreach ($targets as $target) {
            $key = $target->type . ':' . $target->path;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $target;
        }

        return $unique;
    }
}
