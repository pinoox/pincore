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
        $mainDirectory = explode('/', dirname($fileManifest));

        return !empty($mainDirectory[0]) ? $mainDirectory[0] : 'dist';
    }

    public function vite(string $name, ?string $fileManifest = null): array
    {
        $this->outputBuffer = [];
        $fileManifest = $fileManifest ?? $this->fileManifest;

        if ($devUrl = $this->resolveDevServerUrl()) {
            return $this->devTags($devUrl, $name);
        }

        $manifest = $this->loadManifest($fileManifest);
        $mainDirectory = !empty($fileManifest) ? $this->findMainDirectory($fileManifest) : $this->mainDirectory;

        if (!empty($manifest[$name])) {
            $this->processFile($manifest[$name], $manifest, $mainDirectory);
        }

        return $this->outputBuffer;
    }

    public function printVite(string $name, ?string $fileManifest = null): void
    {
        $this->printOutputBuffer($this->vite($name, $fileManifest));
    }

    public function tags(string $name, ?string $fileManifest = null): string
    {
        return implode("\n\t", $this->vite($name, $fileManifest));
    }

    protected function resolveDevServerUrl(): ?string
    {
        $config = FrontendConfig::forThemePath($this->themePath);

        return FrontendConfig::resolveDevServerUrl($this->themePath, $config, $this->fileManifest);
    }

    /**
     * @return list<string>
     */
    protected function devTags(string $devUrl, string $entry): array
    {
        $entry = ltrim($entry, '/');

        return [
            '<script type="module" src="' . $devUrl . '/@vite/client"></script>',
            '<script type="module" src="' . $devUrl . '/' . $entry . '"></script>',
        ];
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
            $this->addFile($fileData['file'], $dir);
        }

        if (!empty($fileData['css'])) {
            foreach ($fileData['css'] as $css) {
                $this->addFile($css, $dir);
            }
        }
    }

    protected function addFile(string $fileName, string $dir): void
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        if (!in_array($extension, ['js', 'css'], true)) {
            return;
        }

        $url = assets($dir . '/' . $fileName);
        $this->outputBuffer[] = match ($extension) {
            'js' => '<script type="module" src="' . $url . '"></script>',
            'css' => '<link rel="stylesheet" href="' . $url . '"/>',
            default => $url,
        };
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

    public static function useVite(string $name, ?string $fileManifest = null): array
    {
        return self::forActiveTheme()->vite($name, $fileManifest);
    }

    public static function usePrintVite(string $name, ?string $fileManifest = null): void
    {
        self::forActiveTheme()->printVite($name, $fileManifest);
    }

    public static function useViteTags(string $name, ?string $fileManifest = null): string
    {
        return self::forActiveTheme()->tags($name, $fileManifest);
    }

    public function cssTags(string $name, ?string $fileManifest = null): string
    {
        $fileManifest = $fileManifest ?? $this->fileManifest;

        if ($this->resolveDevServerUrl() !== null) {
            return '';
        }

        $manifest = $this->loadManifest($fileManifest);
        if (empty($manifest[$name]['css'])) {
            return '';
        }

        $mainDirectory = $this->findMainDirectory($fileManifest);
        $tags = [];

        foreach ($manifest[$name]['css'] as $css) {
            $tags[] = '<link rel="stylesheet" href="' . assets($mainDirectory . '/' . $css) . '"/>';
        }

        return implode("\n\t", $tags);
    }

    public function jsTags(string $name, ?string $fileManifest = null): string
    {
        $fileManifest = $fileManifest ?? $this->fileManifest;

        if ($devUrl = $this->resolveDevServerUrl()) {
            return implode("\n\t", $this->devTags($devUrl, ltrim($name, '/')));
        }

        $manifest = $this->loadManifest($fileManifest);
        if (empty($manifest[$name])) {
            return '';
        }

        $mainDirectory = $this->findMainDirectory($fileManifest);
        $this->outputBuffer = [];
        $this->processFile($manifest[$name], $manifest, $mainDirectory);

        return implode("\n\t", $this->filterTagsByType($this->outputBuffer, 'js'));
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

    public static function useCssTags(string $name, ?string $fileManifest = null): string
    {
        return self::forActiveTheme()->cssTags($name, $fileManifest);
    }

    public static function useJsTags(string $name, ?string $fileManifest = null): string
    {
        return self::forActiveTheme()->jsTags($name, $fileManifest);
    }
}

