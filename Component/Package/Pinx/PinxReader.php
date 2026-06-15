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
        if (!$zip->hasEntry(PinxManifest::MANIFEST_FILE)) {
            throw new Exception('Unsupported package: manifest.json not found.');
        }

        $this->manifestJson = $zip->getEntryContents(PinxManifest::MANIFEST_FILE);
        $manifest = PinxManifest::fromJson($this->manifestJson);
        $manifest->validate();

        if ($zip->hasEntry(PinxSignature::FILE)) {
            $this->signature = PinxSignature::fromJson($zip->getEntryContents(PinxSignature::FILE));
        }

        return $manifest;
    }
}

