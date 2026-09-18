<?php

namespace Pinoox\Component\Cache;

use Pinoox\Component\Cache\Opcode\FakeOpcodeDriver;
use Pinoox\Component\Cache\Opcode\NativeOpcodeDriver;
use Pinoox\Component\Cache\Opcode\OpcodeDriverInterface;
use Pinoox\Portal\Path;

class OpcodeCache
{
    private static ?OpcodeDriverInterface $defaultDriver = null;

    private ?OpcodeDriverInterface $driver;

    public function __construct(?OpcodeDriverInterface $driver = null)
    {
        $this->driver = $driver;
    }

    public static function setDriver(?OpcodeDriverInterface $driver): void
    {
        self::$defaultDriver = $driver;
    }

    public static function fake(bool $available = true): FakeOpcodeDriver
    {
        $fake = new FakeOpcodeDriver($available);
        self::setDriver($fake);

        return $fake;
    }

    public static function restoreDefaultDriver(): void
    {
        self::$defaultDriver = null;
    }

    public function getDriver(): OpcodeDriverInterface
    {
        return $this->driver ?? self::$defaultDriver ??= new NativeOpcodeDriver();
    }

    /**
     * Determine if OPcache is available and enabled.
     */
    public function isAvailable(): bool
    {
        return $this->getDriver()->isAvailable();
    }

    /**
     * Invalidate cached opcode for a single PHP file.
     *
     * @param string $file Path to PHP file
     * @param bool $force Force invalidation regardless of timestamp (defaults to true)
     */
    public function invalidateFile(string $file, bool $force = true): bool
    {
        if (!$this->isAvailable() || !is_file($file)) {
            return false;
        }

        $real = realpath($file);
        $target = $real !== false ? $real : $file;

        return $this->getDriver()->invalidate($target, $force);
    }

    /**
     * Invalidate all PHP files in a directory recursively.
     *
     * @param string $directory Target directory
     * @param bool $force Force invalidation regardless of timestamp
     * @return int Number of successfully invalidated files
     */
    public function invalidateDirectory(string $directory, bool $force = true): int
    {
        if (!$this->isAvailable() || !is_dir($directory)) {
            return 0;
        }

        $count = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    if ($this->invalidateFile($file->getPathname(), $force)) {
                        $count++;
                    }
                }
            }
        } catch (\Throwable) {
            return $count;
        }

        return $count;
    }

    /**
     * Invalidate all PHP files belonging to an App (source files and runtime cache).
     *
     * @param string $package App package name
     * @param bool $force Force invalidation regardless of timestamp
     * @return int Number of successfully invalidated files
     */
    public function invalidateApp(string $package, bool $force = true): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $count = 0;
        $appDir = null;

        try {
            $appDir = Path::app($package);
        } catch (\Throwable) {
        }

        if (!$appDir || !is_dir($appDir)) {
            try {
                $fallback = Path::get('~apps/' . $package);
                if (is_dir($fallback)) {
                    $appDir = $fallback;
                }
            } catch (\Throwable) {
            }
        }

        if ($appDir && is_dir($appDir)) {
            $count += $this->invalidateDirectory($appDir, $force);
        }

        try {
            $cacheRoot = AppCachePath::root($package);
            if (is_dir($cacheRoot)) {
                $count += $this->invalidateDirectory($cacheRoot, $force);
            }

            $bakeAppDir = dirname($cacheRoot);
            if (is_dir($bakeAppDir) && $bakeAppDir !== $cacheRoot) {
                $count += $this->invalidateDirectory($bakeAppDir, $force);
            }
        } catch (\Throwable) {
        }

        return $count;
    }

    /**
     * Invalidate all PHP files in Pinoox Core framework.
     *
     * @param bool $force Force invalidation regardless of timestamp
     * @return int Number of successfully invalidated files
     */
    public function invalidateCore(bool $force = true): int
    {
        if (!$this->isAvailable()) {
            return 0;
        }

        $coreDir = null;

        try {
            $coreDir = Path::pincore();
        } catch (\Throwable) {
        }

        if (!$coreDir || !is_dir($coreDir)) {
            return 0;
        }

        return $this->invalidateDirectory($coreDir, $force);
    }

    /**
     * Explicit full reset of OPcache.
     * Note: Should ONLY be called on explicit operator command.
     */
    public function reset(): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        return $this->getDriver()->reset();
    }
}
