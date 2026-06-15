<?php

namespace Pinoox\Component\Package\Pinx;

use PhpZip\ZipFile;
use Pinoox\Component\Kernel\Exception;
use Pinoox\Portal\Zip;

class PinxReader
{
    private ?PinxManifest $manifest = null;
    private ?ZipFile $zip = null;
    private ?string $manifestJson = null;
    /** @var array<string, mixed>|null */
    private ?array $signature = null;

    public function open(string $path): self
    {
        if (!is_file($path)) {
            throw new Exception('Package file not found: ' . $path);
        }

        $this->zip = Zip::openFile($path);
        $this->manifest = $this->detectManifest($this->zip);

        return $this;
    }

    public function manifest(): PinxManifest
    {
        if ($this->manifest === null) {
            throw new Exception('Package has not been opened.');
        }

        return $this->manifest;
    }

    public function zip(): ZipFile
    {
        if ($this->zip === null) {
            throw new Exception('Package has not been opened.');
        }

        return $this->zip;
    }

    public function close(): void
    {
        if ($this->zip !== null) {
            $this->zip->close();
            $this->zip = null;
        }

        $this->manifestJson = null;
        $this->signature = null;
    }

    public function manifestJson(): string
    {
        if ($this->manifestJson === null) {
            throw new Exception('Package has not been opened.');
        }

        return $this->manifestJson;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function signature(): ?array
    {
        return $this->signature;
    }

    private function detectManifest(ZipFile $zip): PinxManifest
    {
        if ($zip->hasEntry(PinxManifest::MANIFEST_FILE)) {
            $this->manifestJson = $zip->getEntryContents(PinxManifest::MANIFEST_FILE);
            $manifest = PinxManifest::fromJson($this->manifestJson);
            $manifest->validate();

            if ($zip->hasEntry(PinxSignature::FILE)) {
                $this->signature = PinxSignature::fromJson($zip->getEntryContents(PinxSignature::FILE));
            }

            return $manifest;
        }

        if ($zip->hasEntry('app.php')) {
            $app = $this->loadPhpArray($zip, 'app.php');

            return $this->legacyAppManifest($zip, $app);
        }

        if ($zip->hasEntry(PinxManifest::PAYLOAD_PREFIX . 'app.php')) {
            $app = $this->loadPhpArray($zip, PinxManifest::PAYLOAD_PREFIX . 'app.php');

            return $this->legacyAppManifest($zip, $app);
        }

        if ($zip->hasEntry('theme.php')) {
            $theme = $this->loadThemeManifest($zip, 'theme.php');

            return $this->legacyThemeManifest($zip, $theme);
        }

        throw new Exception('Unsupported package: manifest.json, app.php, or theme.php not found.');
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPhpArray(ZipFile $zip, string $entry): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pinx_');
        if ($tmp === false) {
            throw new Exception('Unable to create temporary file for package validation.');
        }

        file_put_contents($tmp, $zip->getEntryContents($entry));
        $data = include $tmp;
        @unlink($tmp);

        if (!is_array($data) || empty($data['package'])) {
            throw new Exception('Package app.php is invalid.');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadThemeManifest(ZipFile $zip, string $entry): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pinx_');
        if ($tmp === false) {
            throw new Exception('Unable to create temporary file for package validation.');
        }

        file_put_contents($tmp, $zip->getEntryContents($entry));
        $data = include $tmp;
        @unlink($tmp);

        if (!is_array($data) || trim((string) ($data['name'] ?? '')) === '') {
            throw new Exception('Invalid legacy theme theme.php');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $app
     */
    private function legacyAppManifest(ZipFile $zip, array $app): PinxManifest
    {
        $langPaths = PinxLabelResolver::langPathsFromZip($zip);
        $resolved = PinxLabelResolver::resolve($app, $langPaths);

        return PinxManifest::fromLegacyApp($app, $resolved);
    }

    /**
     * @param array<string, mixed> $theme
     */
    private function legacyThemeManifest(ZipFile $zip, array $theme): PinxManifest
    {
        $langPaths = PinxLabelResolver::langPathsFromThemeZip($zip);
        $resolved = PinxLabelResolver::resolve($theme, $langPaths);

        return PinxManifest::fromLegacyTheme($theme, $resolved);
    }
}

