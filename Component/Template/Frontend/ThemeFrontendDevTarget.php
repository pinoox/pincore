<?php

namespace Pinoox\Component\Template\Frontend;

use Pinoox\Component\Package\AppManifest;
use Pinoox\Component\Template\Theme\ThemeContextRegistry;
use Pinoox\Portal\App\AppEngine;

/**
 * Resolves frontend dev targets for single-theme apps and theme-context apps (site / panel / …).
 */
final class ThemeFrontendDevTarget
{
    /** Interactive / CLI token to start every vite-capable theme context for one app. */
    public const ALL_CONTEXTS = 'all';

    public static function isAllContexts(?string $selection): bool
    {
        if ($selection === null) {
            return false;
        }

        $selection = strtolower(trim($selection));

        return $selection === self::ALL_CONTEXTS
            || $selection === '__all__'
            || $selection === '*';
    }

    public static function selectionForTargets(?string $selection): ?string
    {
        if ($selection === null || trim($selection) === '' || self::isAllContexts($selection)) {
            return null;
        }

        return trim($selection);
    }

    public static function hasMultipleViteContexts(string $package): bool
    {
        return count(self::targetsForPackage($package)) > 1;
    }

    public static function allContextsChoiceLabel(string $package): string
    {
        $contexts = array_values(array_filter(
            array_column(self::targetsForPackage($package), 'context'),
            static fn ($context): bool => is_string($context) && trim($context) !== '',
        ));

        if ($contexts === []) {
            return 'every vite context';
        }

        return implode(', ', $contexts);
    }

    public static function hasMultipleInstallBuildTargets(string $package): bool
    {
        return count(self::installBuildTargetsForPackage($package)) > 1;
    }

    public static function allInstallBuildTargetsChoiceLabel(string $package): string
    {
        $config = AppManifest::load($package);

        if (ThemeContextRegistry::hasContexts($config)) {
            $contexts = array_values(array_filter(
                array_column(self::installBuildTargetsForPackage($package), 'context'),
                static fn ($context): bool => is_string($context) && trim($context) !== '',
            ));

            if ($contexts === []) {
                return 'all theme contexts';
            }

            return 'all contexts: ' . implode(', ', $contexts);
        }

        $folders = array_map(
            static fn (array $target): string => $target['theme'],
            self::installBuildTargetsForPackage($package),
        );

        if ($folders === []) {
            return 'all themes';
        }

        return 'all themes: ' . implode(', ', $folders);
    }

    /**
     * @return list<array{package: string, theme: string, context: ?string}>
     */
    public static function installBuildTargetsForPackage(string $package, ?string $selection = null): array
    {
        if (!AppEngine::exists($package)) {
            return [];
        }

        $config = AppManifest::load($package);

        if (!ThemeContextRegistry::hasContexts($config)) {
            if ($selection !== null && !self::isAllContexts($selection)) {
                $resolved = self::resolve($package, $selection);

                return [[
                    'package' => $package,
                    'theme' => $resolved['theme'],
                    'context' => null,
                ]];
            }

            $targets = [];

            foreach (ThemeFrontend::listThemeFolders($package) as $folder => $_details) {
                $themePath = self::themePath($package, $folder);

                if (!is_file($themePath . '/package.json')) {
                    continue;
                }

                $targets[] = [
                    'package' => $package,
                    'theme' => $folder,
                    'context' => null,
                ];
            }

            return $targets;
        }

        $selection = self::selectionForTargets($selection);

        if ($selection !== null) {
            $resolved = self::resolve($package, $selection);

            return [[
                'package' => $package,
                'theme' => $resolved['theme'],
                'context' => $resolved['context'],
            ]];
        }

        $targets = [];

        foreach (ThemeContextRegistry::names($config) as $context) {
            $themeFolder = self::themeFolderForContext($config, $context);
            $themePath = self::themePath($package, $themeFolder);

            if (!is_file($themePath . '/package.json')) {
                continue;
            }

            $targets[] = [
                'package' => $package,
                'theme' => $themeFolder,
                'context' => $context,
            ];
        }

        return $targets;
    }

    /**
     * @return array<string, string> context or theme folder => details label
     */
    public static function choices(string $package, bool $viteOnly = true): array
    {
        if (!AppEngine::exists($package)) {
            return [];
        }

        $config = AppManifest::load($package);

        if (ThemeContextRegistry::hasContexts($config)) {
            return self::contextChoices($package, $config, $viteOnly);
        }

        $themes = ThemeFrontend::listThemeFolders($package);

        if (!$viteOnly) {
            return $themes;
        }

        $choices = [];

        foreach ($themes as $folder => $details) {
            if (self::themeFolderSupportsVite($package, $folder)) {
                $choices[$folder] = $details;
            }
        }

        return $choices;
    }

    public static function defaultChoice(string $package): string
    {
        $config = AppManifest::load($package);

        if (ThemeContextRegistry::hasContexts($config)) {
            $choices = self::choices($package, true);
            $preferred = ThemeContextRegistry::defaultName($config);

            if (isset($choices[$preferred])) {
                return $preferred;
            }

            if ($choices !== []) {
                return (string) array_key_first($choices);
            }

            return $preferred;
        }

        $defaultTheme = (string) AppEngine::config($package)->get('theme', 'default');
        $choices = self::choices($package, false);

        if (isset($choices[$defaultTheme])) {
            return $defaultTheme;
        }

        return $choices !== [] ? (string) array_key_first($choices) : $defaultTheme;
    }

    /**
     * @return array{theme: string, context: ?string}
     */
    public static function resolve(string $package, string $selection = ''): array
    {
        $selection = trim($selection);
        $config = AppManifest::load($package);

        if (!ThemeContextRegistry::hasContexts($config)) {
            $folder = $selection !== '' ? $selection : self::defaultChoice($package);

            return ['theme' => $folder, 'context' => null];
        }

        if ($selection !== '' && in_array($selection, ThemeContextRegistry::names($config), true)) {
            return [
                'theme' => self::themeFolderForContext($config, $selection),
                'context' => $selection,
            ];
        }

        if ($selection !== '') {
            foreach (ThemeContextRegistry::names($config) as $context) {
                if (self::themeFolderForContext($config, $context) === $selection) {
                    return ['theme' => $selection, 'context' => $context];
                }
            }
        }

        $default = self::defaultChoice($package);

        return [
            'theme' => self::themeFolderForContext($config, $default),
            'context' => $default,
        ];
    }

    /**
     * @return list<array{package: string, theme: string, context: ?string}>
     */
    public static function targetsForPackage(string $package, ?string $selection = null): array
    {
        $config = AppManifest::load($package);

        if (!ThemeContextRegistry::hasContexts($config)) {
            $resolved = self::resolve($package, $selection ?? '');

            return [[
                'package' => $package,
                'theme' => $resolved['theme'],
                'context' => null,
            ]];
        }

        $selection = self::selectionForTargets($selection);

        if ($selection !== null) {
            $resolved = self::resolve($package, $selection);

            return [[
                'package' => $package,
                'theme' => $resolved['theme'],
                'context' => $resolved['context'],
            ]];
        }

        $targets = [];

        foreach (ThemeContextRegistry::names($config) as $context) {
            if (!self::supportsVite($package, $context)) {
                continue;
            }

            $targets[] = [
                'package' => $package,
                'theme' => self::themeFolderForContext($config, $context),
                'context' => $context,
            ];
        }

        return $targets;
    }

    /**
     * @return list<array{package: string, theme: string, context: ?string}>
     */
    public static function platformTargets(): array
    {
        $targets = [];

        foreach (ThemeFrontend::packagesWithViteDevForPlatform() as $package) {
            foreach (self::targetsForPackage($package) as $target) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    public static function supportsVite(string $package, ?string $contextOrTheme = null): bool
    {
        if (!AppEngine::exists($package)) {
            return false;
        }

        $config = AppManifest::load($package);

        if (ThemeContextRegistry::hasContexts($config)) {
            if ($contextOrTheme === null || trim($contextOrTheme) === '') {
                foreach (ThemeContextRegistry::names($config) as $context) {
                    if (self::contextSupportsVite($package, $config, $context)) {
                        return true;
                    }
                }

                return false;
            }

            $resolved = self::resolve($package, $contextOrTheme);

            return self::contextSupportsVite($package, $config, (string) $resolved['context']);
        }

        $folder = $contextOrTheme !== null && trim($contextOrTheme) !== ''
            ? trim($contextOrTheme)
            : (string) AppEngine::config($package)->get('theme', 'default');

        return self::themeFolderSupportsVite($package, $folder);
    }

    public static function stackLabel(string $package, ?string $context = null): string
    {
        if ($context !== null && $context !== '') {
            return $context;
        }

        if (str_starts_with($package, 'com_pinoox_')) {
            return \Pinoox\Component\Package\PackageName::shortLabel($package);
        }

        return $package;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private static function contextChoices(string $package, array $config, bool $viteOnly): array
    {
        $choices = [];

        foreach (ThemeContextRegistry::names($config) as $context) {
            if ($viteOnly && !self::contextSupportsVite($package, $config, $context)) {
                continue;
            }

            $themeFolder = self::themeFolderForContext($config, $context);
            $parts = ['theme: ' . $themeFolder];

            if ($context === ThemeContextRegistry::defaultName($config)) {
                $parts[] = 'default';
            }

            if (self::contextSupportsVite($package, $config, $context)) {
                $parts[] = 'vite';
            }

            $choices[$context] = implode(', ', $parts);
        }

        return $choices;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function contextSupportsVite(string $package, array $config, string $context): bool
    {
        $themeFolder = self::themeFolderForContext($config, $context);

        if (!self::themeFolderSupportsVite($package, $themeFolder)) {
            return false;
        }

        $effective = ThemeContextRegistry::effectiveConfig($config, $context);
        $themePath = self::themePath($package, $themeFolder);
        $frontendConfig = FrontendConfig::forThemePath($themePath);

        if (isset($effective['frontend']) && is_array($effective['frontend'])) {
            $frontendConfig = array_replace_recursive($frontendConfig, $effective['frontend']);
        }

        return FrontendConfig::usesViteAssets($frontendConfig);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function themeFolderForContext(array $config, string $context): string
    {
        $ctx = ThemeContextRegistry::context($config, $context);
        $theme = $ctx['theme'] ?? null;

        if (is_string($theme) && trim($theme) !== '') {
            return trim($theme);
        }

        return (string) ($config['theme'] ?? 'default');
    }

    private static function themeFolderSupportsVite(string $package, string $themeFolder): bool
    {
        $themePath = self::themePath($package, $themeFolder);

        if (!is_dir($themePath)) {
            return false;
        }

        $config = FrontendConfig::forThemePath($themePath);

        if (!FrontendConfig::usesViteAssets($config)) {
            return false;
        }

        $packageJson = $themePath . '/package.json';

        if (!is_file($packageJson)) {
            return false;
        }

        $data = json_decode((string) file_get_contents($packageJson), true);

        if (!is_array($data)) {
            return false;
        }

        $scripts = $data['scripts'] ?? [];

        return isset($scripts['dev']) && trim((string) $scripts['dev']) !== '';
    }

    private static function themePath(string $package, string $themeFolder): string
    {
        return ThemeFrontendPaths::themeDir($package, $themeFolder);
    }
}
