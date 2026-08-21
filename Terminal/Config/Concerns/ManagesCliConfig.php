<?php

namespace Pinoox\Terminal\Config\Concerns;

use Pinoox\Component\Package\ManifestPinkerLoader;
use Pinoox\Component\Store\Config\Config;
use Pinoox\Component\Store\Config\ConfigInterface;
use Pinoox\Component\Store\Config\Strategy\FileConfigStrategy;
use Pinoox\Component\Template\Theme\ThemeManifest;
use Pinoox\Component\Template\Theme\ThemeStack;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\Config as ConfigPortal;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

trait ManagesCliConfig
{
    use SelectsPackage;

    /**
     * @return array{package:string, kind:string, file:string, label:string, theme:?string, config:ConfigInterface}
     */
    protected function resolveConfigTarget(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $sectionTitle = 'Config package',
    ): array {
        $package = trim((string) ($input->getArgument('package') ?: ''));

        if ($package === '') {
            $package = $this->resolvePackageRequired($input, $output, $io, [
                'sectionTitle' => $sectionTitle,
                'appsOnly' => false,
            ]);
        }

        $file = (string) ($input->getOption('file') ?: 'app.php');
        $theme = $input->hasOption('theme') ? $input->getOption('theme') : null;
        $theme = is_string($theme) && $theme !== '' ? $theme : null;

        return $this->openConfigTarget($package, $file, $theme);
    }

    /**
     * @return array{package:string, kind:string, file:string, label:string, theme:?string, config:ConfigInterface}
     */
    protected function openConfigTarget(string $package, string $file, ?string $theme = null): array
    {
        $kind = $this->configFileKind($file);

        if ($kind === 'app') {
            if ($this->isPlatformPackage($package)) {
                throw new \InvalidArgumentException('platform has no app.php. Use --file=database (or another config name).');
            }

            if (!AppEngine::exists($package)) {
                throw new \InvalidArgumentException('App not found: ' . $package);
            }

            return [
                'package' => $package,
                'kind' => 'app',
                'file' => 'app.php',
                'label' => 'app.php',
                'theme' => null,
                'config' => AppEngine::config($package),
            ];
        }

        if ($kind === 'theme') {
            if ($this->isPlatformPackage($package)) {
                throw new \InvalidArgumentException('platform has no theme.php. Pass an app package.');
            }

            if (!AppEngine::exists($package)) {
                throw new \InvalidArgumentException('App not found: ' . $package);
            }

            $themeName = $this->resolveThemeName($package, $theme);
            $pathTheme = ThemeStack::pathTheme($package);
            $manifest = ThemeManifest::load($package, $themeName, $pathTheme);

            if ($manifest === null) {
                throw new \InvalidArgumentException(sprintf(
                    'Theme "%s" was not found for %s (missing theme/%s/theme.php).',
                    $themeName,
                    $package,
                    $themeName,
                ));
            }

            $mainFile = rtrim($manifest->path(), '/\\') . '/' . ThemeManifest::FILE;
            $pinker = ManifestPinkerLoader::pinkerFor($mainFile, ManifestPinkerLoader::themeDefaults());
            $config = new Config(new FileConfigStrategy($pinker));

            return [
                'package' => $package,
                'kind' => 'theme',
                'file' => 'theme.php',
                'label' => 'theme/' . $themeName . '/theme.php',
                'theme' => $themeName,
                'config' => $config,
            ];
        }

        $name = $this->configStoreName($file);

        if ($this->isPlatformPackage($package)) {
            $config = ConfigPortal::name('~' . $name);

            return [
                'package' => 'platform',
                'kind' => 'config',
                'file' => $name,
                'label' => $name . '.config.php',
                'theme' => null,
                'config' => $config,
            ];
        }

        if (!AppEngine::exists($package)) {
            throw new \InvalidArgumentException('App not found: ' . $package);
        }

        return [
            'package' => $package,
            'kind' => 'config',
            'file' => $name,
            'label' => 'config/' . $name . '.config.php',
            'theme' => null,
            'config' => ConfigPortal::name($package . ':' . $name),
        ];
    }

    protected function isPlatformPackage(string $package): bool
    {
        return $package === 'platform' || $package === 'pincore';
    }

    protected function configFileKind(string $file): string
    {
        $normalized = strtolower(str_replace('\\', '/', trim($file)));
        $normalized = ltrim($normalized, '/');

        if ($normalized === '' || $normalized === 'app' || $normalized === 'app.php') {
            return 'app';
        }

        if ($normalized === 'theme' || $normalized === 'theme.php' || str_ends_with($normalized, '/theme.php')) {
            return 'theme';
        }

        return 'config';
    }

    protected function configStoreName(string $file): string
    {
        $normalized = str_replace('\\', '/', trim($file));
        $normalized = ltrim($normalized, '/');

        if (str_starts_with($normalized, 'config/')) {
            $normalized = substr($normalized, strlen('config/'));
        }

        if (str_ends_with(strtolower($normalized), '.config.php')) {
            $normalized = substr($normalized, 0, -strlen('.config.php'));
        } elseif (str_ends_with(strtolower($normalized), '.php')) {
            $normalized = substr($normalized, 0, -4);
        }

        $normalized = trim($normalized, '/');

        if ($normalized === '' || !preg_match('/^[A-Za-z0-9_\/-]+$/', $normalized)) {
            throw new \InvalidArgumentException('Invalid config file: ' . $file);
        }

        return $normalized;
    }

    protected function resolveThemeName(string $package, ?string $theme): string
    {
        if (is_string($theme) && $theme !== '') {
            return $theme;
        }

        $active = AppEngine::config($package)->get('theme', 'default');

        if (is_array($active)) {
            $name = $active['name'] ?? $active['theme'] ?? 'default';
            $active = is_string($name) && $name !== '' ? $name : 'default';
        }

        return is_string($active) && $active !== '' ? $active : 'default';
    }

    /**
     * @return array<string, mixed>
     */
    protected function collectConfigPairs(InputInterface $input): array
    {
        $pairs = [];

        foreach ((array) $input->getOption('set') as $pair) {
            if (!is_string($pair) || $pair === '') {
                continue;
            }

            [$key, $value] = $this->parseConfigPair($pair);
            $pairs[$key] = $value;
        }

        $key = trim((string) ($input->getArgument('key') ?: ''));
        $value = $input->getArgument('value');

        if ($key !== '' && str_contains($key, '=') && ($value === null || $value === '')) {
            [$key, $parsed] = $this->parseConfigPair($key);
            $pairs[$key] = $parsed;

            return $pairs;
        }

        if ($key !== '') {
            if ($value === null) {
                throw new \InvalidArgumentException('Value is required. Use: config:set {package} {key} {value}');
            }

            $pairs[$key] = $this->parseCliValue((string) $value);
        }

        return $pairs;
    }

    /**
     * @return array{0:string, 1:mixed}
     */
    protected function parseConfigPair(string $pair): array
    {
        $eq = strpos($pair, '=');

        if ($eq === false) {
            throw new \InvalidArgumentException('Expected key=value, got: ' . $pair);
        }

        $key = trim(substr($pair, 0, $eq));
        $value = substr($pair, $eq + 1);

        if ($key === '') {
            throw new \InvalidArgumentException('Invalid key in pair: ' . $pair);
        }

        return [$key, $this->parseCliValue($value)];
    }

    protected function parseCliValue(string $raw): mixed
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            return '';
        }

        $lower = strtolower($trimmed);

        if ($lower === 'true') {
            return true;
        }

        if ($lower === 'false') {
            return false;
        }

        if ($lower === 'null') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $trimmed) === 1) {
            return (int) $trimmed;
        }

        if (preg_match('/^-?\d+\.\d+$/', $trimmed) === 1) {
            return (float) $trimmed;
        }

        $len = strlen($trimmed);
        if ($len >= 2) {
            $first = $trimmed[0];
            $last = $trimmed[$len - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($trimmed, 1, $len - 2);
            }

            if (($first === '{' && $last === '}') || ($first === '[' && $last === ']')) {
                $decoded = json_decode($trimmed, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }
        }

        return $raw;
    }

    protected function formatCliValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: 'null';
    }

    protected function assertWritableKey(array $target, string $key): void
    {
        if ($target['kind'] === 'app' && $key === 'package') {
            throw new \InvalidArgumentException('The app.php "package" key cannot be changed from CLI.');
        }
    }

    protected function rememberConfigChange(string $package): void
    {
        if ($this->isPlatformPackage($package) || !AppEngine::exists($package)) {
            return;
        }

        AppEngine::__rebuild();
    }
}
