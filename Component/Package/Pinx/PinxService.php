<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Package\Engine\AppEngine;

/**
 * Facade for pinx install/update/uninstall and package inspection.
 */
final class PinxService
{
    public function __construct(
        private readonly AppEngine $engine,
        private readonly string $tmpPath,
    ) {
    }

    public function tmpPath(): string
    {
        return $this->tmpPath;
    }

    public function engine(): AppEngine
    {
        return $this->engine;
    }

    public function installer(): PinxInstaller
    {
        return new PinxInstaller($this->engine, $this->tmpPath);
    }

    public function uninstaller(): PinxUninstaller
    {
        return new PinxUninstaller($this->engine);
    }

    public function builder(): PinxBuilder
    {
        return new PinxBuilder($this->engine);
    }

    public function platformBuilder(): PlatformBuilder
    {
        return new PlatformBuilder();
    }

    /**
     * @param array<string, mixed> $options
     */
    public function install(string $packagePath, array $options = []): PinxInstallResult
    {
        return $this->installer()->install($packagePath, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function uninstallApp(string $package, array $options = []): PinxUninstallResult
    {
        return $this->uninstaller()->uninstallApp($package, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function uninstallTheme(string $package, string $themeName, array $options = []): PinxUninstallResult
    {
        return $this->uninstaller()->uninstallTheme($package, $themeName, $options);
    }

    public function manifest(string $packagePath): PinxManifest
    {
        $reader = new PinxReader();
        $reader->open($packagePath);

        try {
            return $reader->manifest();
        } finally {
            $reader->close();
        }
    }

    /**
     * @template TReturn
     * @param callable(PinxReader): TReturn $callback
     * @return TReturn
     */
    public function withReader(string $packagePath, callable $callback): mixed
    {
        $reader = new PinxReader();
        $reader->open($packagePath);

        try {
            return $callback($reader);
        } finally {
            $reader->close();
        }
    }

    public function resolveMode(PinxManifest $manifest, bool $force = false): string
    {
        return $this->installer()->resolveMode($manifest, $force);
    }
}
