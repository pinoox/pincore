<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Kernel\Exception;
use Pinoox\Component\Package\AppComposerVendor;
use Pinoox\Component\Package\Engine\AppEngine;
use Pinoox\Component\Package\PackageName;
use ZipArchive;

class PinxBuilder
{
    public function __construct(
        private AppEngine $engine,
        private PinxFileSelector $selector = new PinxFileSelector(),
    ) {
    }

    /**
     * @param array{
     *     sign?: bool,
     *     sign_key?: ?string,
     *     key_id?: ?string,
     *     progress?: callable(string $phase, string $message, ?int $percent=null): void
     * } $options
     * @return array{path: string, manifest: PinxManifest, files: int, signed: bool, signature: ?array, composer: bool}
     */
    public function build(string $package, ?string $outputPath = null, array $options = []): array
    {
        if (!$this->engine->exists($package)) {
            throw new Exception('Package not found: ' . $package);
        }

        $build = PinxBuildConfig::resolve($this->engine, $package);
        $appConfig = PinxBuildConfig::appConfigArray($this->engine, $package);
        $appPackage = (string) ($appConfig['package'] ?? $package);

        if ($build['type'] === PinxManifest::TYPE_APP) {
            $error = PackageName::validationError($appPackage);

            if ($error !== null) {
                throw new Exception('Cannot build package: ' . $error);
            }
        }

        $targetApp = (string) ($build['target_app'] ?? $appPackage);

        if ($build['type'] === PinxManifest::TYPE_THEME) {
            $error = PackageName::validationError($targetApp);

            if ($error !== null) {
                throw new Exception('Cannot build theme package: invalid target app. ' . $error);
            }
        }

        $manifest = PinxManifest::fromAppConfig($appConfig, $build['type'], [
            'target_app' => $build['target_app'],
            'theme_name' => $build['theme_name'],
            'minpin' => $build['minpin'],
        ]);

        $packagePath = $this->engine->path($package);
        $sourcePath = $build['type'] === PinxManifest::TYPE_THEME
            ? $packagePath . '/theme/' . $build['theme_name']
            : $packagePath;

        if (!is_dir($sourcePath)) {
            throw new Exception('Build source path not found: ' . $sourcePath);
        }

        $composerPrepared = false;
        $alwaysInclude = [];
        $composerPackages = [];

        if ($build['type'] === PinxManifest::TYPE_APP && $build['composer'] && AppComposerVendor::hasComposerJson($packagePath)) {
            $this->reportProgress($options, 'composer', 'Validating app Composer vendor...', 10);
            $composerResult = AppComposerVendor::prepare($packagePath);

            if ($composerResult['prepared'] && is_string($composerResult['vendor_dir'])) {
                $composerPrepared = true;
                $alwaysInclude[] = [
                    'path' => $composerResult['vendor_dir'],
                    'as' => $composerResult['vendor_as'] ?? AppComposerVendor::VENDOR_SUBDIR,
                ];
                $composerPackages = $composerResult['packages'] ?? [];
            }
        }

        return $this->finalizeBuild(
            $package,
            $packagePath,
            $sourcePath,
            $build,
            $manifest,
            $appConfig,
            $outputPath,
            $alwaysInclude,
            $composerPrepared,
            $composerPackages,
            $options,
        );
    }

    /**
     * @param array{
     *     type: string,
     *     target_app: string,
     *     theme_name: string,
     *     minpin: int,
     *     gitignore: bool,
     *     exclude: list<string>,
     *     include_themes: list<string>,
     *     composer: bool,
     *     sign: array{enabled: bool, require_signature: bool, key_path: ?string, key_id: ?string}
     * } $build
     * @param list<string|array{path: string, as?: string}> $alwaysInclude
     * @param array{sign?: bool, sign_key?: ?string, key_id?: ?string} $options
     * @return array{path: string, manifest: PinxManifest, files: int, signed: bool, signature: ?array, composer: bool, composer_packages: list<string>}
     */
    private function finalizeBuild(
        string $package,
        string $packagePath,
        string $sourcePath,
        array $build,
        PinxManifest $manifest,
        array $appConfig,
        ?string $outputPath,
        array $alwaysInclude,
        bool $composerPrepared,
        array $composerPackages,
        array $options,
    ): array {
        $buildConfig = [
            'gitignore' => $build['gitignore'],
            'exclude' => $build['exclude'],
            'include_themes' => $build['type'] === PinxManifest::TYPE_APP ? $build['include_themes'] : [],
            'always_include' => $alwaysInclude,
        ];

        $this->reportProgress($options, 'collect', 'Collecting application files...', 25);
        $payloadFiles = $this->selector->payloadFiles($sourcePath, $buildConfig);
        $fileCount = count($payloadFiles);

        if ($fileCount === 0) {
            throw new Exception(
                'No files selected for pinx build. Source: ' . $sourcePath
                . ' (check build excludes or app path mapping).',
            );
        }

        if ($build['type'] === PinxManifest::TYPE_APP) {
            $manifest = PinxManifest::fromArray(
                PinxIcon::enrichManifest($manifest->toArray(), $appConfig, $payloadFiles),
            );
        }

        $manifest->validate();

        $outputPath ??= $this->defaultOutputPath($package, $manifest);
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Failed to create pinx archive: ' . $outputPath);
        }

        $manifestJson = $manifest->toJson();
        $zip->addFromString(PinxManifest::MANIFEST_FILE, $manifestJson);

        $this->reportProgress($options, 'archive', 'Creating .pinx archive...', 40);

        /** @var array<string, string> $payloadHashes */
        $payloadHashes = [];
        $processed = 0;
        $total = max(count($payloadFiles), 1);
        foreach ($payloadFiles as $relativePath => $realPath) {
            $entry = PinxManifest::PAYLOAD_PREFIX . $this->payloadEntry($build['type'], $build['theme_name'], $relativePath);
            $payloadHashes[$entry] = hash_file('sha256', $realPath) ?: '';
            $zip->addFile($realPath, $entry);
            $processed++;
            if ($processed === $total || $processed % 50 === 0) {
                $percent = 40 + (int) floor(($processed / $total) * 45);
                $this->reportProgress($options, 'archive', 'Adding files (' . $processed . '/' . $total . ')...', $percent);
            }
        }

        $signature = null;
        $signed = false;
        if ($this->shouldSign($package, $packagePath, $build, $options)) {
            $this->reportProgress($options, 'sign', 'Signing package...', 92);
            $key = $this->resolveSigningKey($package, $packagePath, $build, $options);

            if (!empty($options['key_id'])) {
                $key['key_id'] = (string) $options['key_id'];
            } elseif (!empty($build['sign']['key_id'])) {
                $key['key_id'] = (string) $build['sign']['key_id'];
            } elseif ($key['key_id'] === 'main') {
                $key['key_id'] = $package . ':main';
            }

            $signature = PinxSignature::create($manifestJson, $payloadHashes, $key);
            $zip->addFromString(
                PinxSignature::FILE,
                json_encode($signature, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            );
            $signed = true;
        }

        $zip->close();

        $this->reportProgress($options, 'done', 'Build finished.', 100);

        return [
            'path' => $outputPath,
            'manifest' => $manifest,
            'files' => $fileCount,
            'signed' => $signed,
            'signature' => $signature,
            'composer' => $composerPrepared,
            'composer_packages' => $composerPackages,
        ];
    }

    /**
     * @param array{sign: array{enabled: bool, require_signature: bool, key_path: ?string, key_id: ?string}} $build
     * @param array{sign?: bool, sign_key?: ?string} $options
     */
    private function shouldSign(string $package, string $packagePath, array $build, array $options): bool
    {
        if (array_key_exists('sign', $options)) {
            return (bool) $options['sign'];
        }

        if ($build['sign']['enabled']) {
            return true;
        }

        return is_file(PinxSignKey::defaultKeyPath($package, $packagePath));
    }

    /**
     * @param array{sign: array{enabled: bool, require_signature: bool, key_path: ?string, key_id: ?string}} $build
     * @param array{sign?: bool, sign_key?: ?string, key_id?: ?string} $options
     * @return array{key_id: string, algorithm: string, public_key: string, secret_key: string}
     */
    private function resolveSigningKey(string $package, string $packagePath, array $build, array $options): array
    {
        $path = $options['sign_key']
            ?? ($build['sign']['key_path'] !== null
                ? PinxPaths::resolveKeyPath($package, $packagePath, $build['sign']['key_path'])
                : null)
            ?? PinxSignKey::defaultKeyPath($package, $packagePath);

        if (!is_string($path) || !is_file($path)) {
            throw new Exception('Signing key not found. Run pinx:sign-keygen or pass --sign-key.');
        }

        $key = PinxSignKey::load($path);
        if (($key['algorithm'] ?? '') !== PinxSignKey::ALGORITHM) {
            throw new Exception('Unsupported signing algorithm: ' . ($key['algorithm'] ?? 'unknown'));
        }

        return $key;
    }

    private function payloadEntry(string $type, string $themeName, string $relativePath): string
    {
        if ($type === PinxManifest::TYPE_THEME) {
            return 'theme/' . $themeName . '/' . ltrim($relativePath, '/');
        }

        return ltrim($relativePath, '/');
    }

    private function defaultOutputPath(string $package, PinxManifest $manifest): string
    {
        return PinxPaths::defaultReleasePath($package, $manifest);
    }

    /**
     * @param array{progress?: callable(string, string, ?int): void} $options
     */
    private function reportProgress(array $options, string $phase, string $message, ?int $percent = null): void
    {
        $callback = $options['progress'] ?? null;

        if (!is_callable($callback)) {
            return;
        }

        $callback($phase, $message, $percent);
    }
}

