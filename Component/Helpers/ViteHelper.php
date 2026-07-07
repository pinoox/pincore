<?php

namespace Pinoox\Component\Helpers;

use Pinoox\Component\Template\Frontend\FrontendConfig;
use Pinoox\Portal\View;

class ViteHelper
{
    protected string $fileManifest;
    protected string $mainDirectory;
    protected string $themePath;
    protected array $outputBuffer = [];

    public function __construct(string $themePath, ?string $fileManifest = null)
    {
        $this->themePath = rtrim(str_replace('\\', '/', $themePath), '/');
        $config = FrontendConfig::forThemePath($this->themePath);
        $this->fileManifest = $fileManifest ?? FrontendConfig::manifestRelativePath($config) ?? FrontendConfig::VITE_MANIFEST;
        $this->mainDirectory = $this->findMainDirectory($this->fileManifest);
    }

    protected function findMainDirectory(string $fileManifest): string
    {
        return FrontendConfig::outDirFromManifestPath($fileManifest)
            ?? FrontendConfig::buildOutDir(
                FrontendConfig::forThemePath($this->themePath),
                $this->themePath,
            );
    }

    /**
     * @param string|list<string> $name
     * @return list<string>
     */
    public function vite(string|array $name, ?string $fileManifest = null): array
    {
        $entries = $this->normalizeEntries($name);
        $this->outputBuffer = [];
        $fileManifest = $fileManifest ?? $this->fileManifest;

        if ($devUrl = $this->resolveDevServerUrl()) {
            return $this->devTags($devUrl, $entries);
        }

        $manifest = $this->loadManifest($fileManifest);
        $mainDirectory = !empty($fileManifest) ? $this->findMainDirectory($fileManifest) : $this->mainDirectory;

        foreach ($entries as $entry) {
            if (!empty($manifest[$entry])) {
                $this->processFile($manifest[$entry], $manifest, $mainDirectory);
            }
        }

        return $this->uniqueTags($this->outputBuffer);
    }

    /**
     * @param string|list<string> $name
     */
    public function printVite(string|array $name, ?string $fileManifest = null): void
    {
        $this->printOutputBuffer($this->vite($name, $fileManifest));
    }

    /**
     * @param string|list<string> $name
     */
    public function tags(string|array $name, ?string $fileManifest = null): string
    {
        return implode("\n\t", $this->vite($name, $fileManifest));
    }

    /**
     * Resolve a versioned asset URL from the Vite manifest (Laravel Vite::asset style).
     */
    public function asset(string $path, ?string $fileManifest = null): ?string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $fileManifest = $fileManifest ?? $this->fileManifest;

        if ($devUrl = $this->resolveDevServerUrl()) {
            return rtrim($devUrl, '/') . '/' . $path;
        }

        $manifest = $this->loadManifest($fileManifest);
        $mainDirectory = $this->findMainDirectory($fileManifest);

        if (!empty($manifest[$path]['file'])) {
            return assets($mainDirectory . '/' . $manifest[$path]['file']);
        }

        foreach ($manifest as $chunk) {
            if (!is_array($chunk) || empty($chunk['file'])) {
                continue;
            }

            if ($chunk['file'] === $path || str_ends_with($chunk['file'], '/' . $path)) {
                return assets($mainDirectory . '/' . $chunk['file']);
            }
        }

        return null;
    }

    protected function resolveDevServerUrl(): ?string
    {
        $config = FrontendConfig::forThemePath($this->themePath);

        return FrontendConfig::resolveDevServerUrl($this->themePath, $config, $this->fileManifest);
    }

    /**
     * @param list<string> $entries
     * @return list<string>
     */
    protected function devTags(string $devUrl, array $entries): array
    {
        $devUrl = rtrim($devUrl, '/');
        $tags = ['<script type="module" src="' . $devUrl . '/@vite/client"></script>'];

        foreach ($entries as $entry) {
            $tags[] = '<script type="module" src="' . $devUrl . '/' . ltrim($entry, '/') . '"></script>';
        }

        return $tags;
    }

    /**
     * @param string|list<string> $name
     * @return list<string>
     */
    protected function normalizeEntries(string|array $name): array
    {
        if (is_string($name)) {
            return [ltrim(str_replace('\\', '/', $name), '/')];
        }

        $entries = [];

        foreach ($name as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                continue;
            }

            $entries[] = ltrim(str_replace('\\', '/', trim($entry)), '/');
        }

        return $entries !== [] ? $entries : ['src/main.js'];
    }

    protected function loadManifest(string $fileManifest): array
    {
        $pathManifest = $this->themePath . '/' . ltrim($fileManifest, '/');
        if (is_file($pathManifest)) {
            $manifest = file_get_contents($pathManifest);

            return json_decode($manifest, true) ?: [];
        }

        return [];
    }

    protected function processFile(array $fileData, array $manifest, string $dir, array $processed = []): void
    {
        if (!empty($fileData['imports'])) {
            foreach ($fileData['imports'] as $importKey) {
                if (empty($processed[$importKey]) && !empty($manifest[$importKey])) {
                    $processed[$importKey] = true;
                    $this->processFile($manifest[$importKey], $manifest, $dir, $processed);
                }
            }
        }

        if (!empty($fileData['file'])) {
            $this->addFile($fileData['file'], $dir, !empty($fileData['isEntry']));
        }

        if (!empty($fileData['css'])) {
            foreach ($fileData['css'] as $css) {
                $this->addFile($css, $dir);
            }
        }
    }

    protected function addFile(string $fileName, string $dir, bool $isEntry = false): void
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        if (!in_array($extension, ['js', 'css'], true)) {
            return;
        }

        $url = assets($dir . '/' . $fileName);

        if ($extension === 'js' && $isEntry) {
            $this->outputBuffer[] = '<link rel="modulepreload" href="' . $url . '"/>';
        }

        $this->outputBuffer[] = match ($extension) {
            'js' => '<script type="module" src="' . $url . '"></script>',
            'css' => '<link rel="stylesheet" href="' . $url . '"/>',
            default => $url,
        };
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    protected function uniqueTags(array $tags): array
    {
        return array_values(array_unique($tags));
    }

    protected function printOutputBuffer(array $output): void
    {
        if ($output === []) {
            return;
        }

        echo $output[0];
        for ($i = 1, $count = count($output); $i < $count; $i++) {
            echo "\n\t" . $output[$i];
        }
    }

    public static function forActiveTheme(): self
    {
        return new self(View::path()->current());
    }

    /**
     * @param string|list<string> $name
     * @return list<string>
     */
    public static function useVite(string|array $name, ?string $fileManifest = null): array
    {
        return self::forActiveTheme()->vite($name, $fileManifest);
    }

    /**
     * @param string|list<string> $name
     */
    public static function usePrintVite(string|array $name, ?string $fileManifest = null): void
    {
        self::forActiveTheme()->printVite($name, $fileManifest);
    }

    /**
     * @param string|list<string> $name
     */
    public static function useViteTags(string|array $name, ?string $fileManifest = null): string
    {
        return self::forActiveTheme()->tags($name, $fileManifest);
    }

    public static function useAsset(string $path, ?string $fileManifest = null): ?string
    {
        return self::forActiveTheme()->asset($path, $fileManifest);
    }

    /**
     * @param string|list<string> $name
     */
    public function cssTags(string|array $name, ?string $fileManifest = null): string
    {
        $entries = $this->normalizeEntries($name);
        $fileManifest = $fileManifest ?? $this->fileManifest;

        if ($this->resolveDevServerUrl() !== null) {
            return '';
        }

        $manifest = $this->loadManifest($fileManifest);
        $mainDirectory = $this->findMainDirectory($fileManifest);
        $tags = [];

        foreach ($entries as $entry) {
            if (empty($manifest[$entry]['css'])) {
                continue;
            }

            foreach ($manifest[$entry]['css'] as $css) {
                $tags[] = '<link rel="stylesheet" href="' . assets($mainDirectory . '/' . $css) . '"/>';
            }
        }

        return implode("\n\t", $this->uniqueTags($tags));
    }

    /**
     * @param string|list<string> $name
     */
    public function jsTags(string|array $name, ?string $fileManifest = null): string
    {
        $entries = $this->normalizeEntries($name);
        $fileManifest = $fileManifest ?? $this->fileManifest;

        if ($devUrl = $this->resolveDevServerUrl()) {
            $tags = $this->devTags($devUrl, $entries);

            return implode("\n\t", $this->filterTagsByType($tags, 'js'));
        }

        $manifest = $this->loadManifest($fileManifest);
        $mainDirectory = $this->findMainDirectory($fileManifest);
        $this->outputBuffer = [];

        foreach ($entries as $entry) {
            if (!empty($manifest[$entry])) {
                $this->processFile($manifest[$entry], $manifest, $mainDirectory);
            }
        }

        return implode("\n\t", $this->filterTagsByType($this->uniqueTags($this->outputBuffer), 'js'));
    }

    /**
     * @param list<string> $tags
     * @return list<string>
     */
    protected function filterTagsByType(array $tags, string $type): array
    {
        $needle = $type === 'css' ? '<link' : '<script';

        return array_values(array_filter(
            $tags,
            static fn (string $tag): bool => str_starts_with($tag, $needle),
        ));
    }

    /**
     * @param string|list<string> $name
     */
    public static function useCssTags(string|array $name, ?string $fileManifest = null): string
    {
        return self::forActiveTheme()->cssTags($name, $fileManifest);
    }

    /**
     * @param string|list<string> $name
     */
    public static function useJsTags(string|array $name, ?string $fileManifest = null): string
    {
        return self::forActiveTheme()->jsTags($name, $fileManifest);
    }
}
