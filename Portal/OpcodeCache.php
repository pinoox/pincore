<?php

namespace Pinoox\Portal;

use Pinoox\Component\Cache\Opcode\FakeOpcodeDriver;
use Pinoox\Component\Cache\Opcode\OpcodeDriverInterface;
use Pinoox\Component\Cache\OpcodeCache as ObjectPortal1;
use Pinoox\Component\Source\Portal;

/**
 * @method static bool isAvailable()
 * @method static bool invalidateFile(string $file, bool $force = true)
 * @method static int invalidateDirectory(string $directory, bool $force = true)
 * @method static int invalidateApp(string $package, bool $force = true)
 * @method static int invalidateCore(bool $force = true)
 * @method static bool reset()
 * @method static FakeOpcodeDriver fake(bool $available = true)
 * @method static void setDriver(?OpcodeDriverInterface $driver)
 * @method static void restoreDefaultDriver()
 * @method static ObjectPortal1 ___()
 *
 * @see ObjectPortal1
 */
class OpcodeCache extends Portal
{
    public static function __register(): void
    {
        self::__bind(ObjectPortal1::class);
    }

    public static function __name(): string
    {
        return 'opcode.cache';
    }

    public static function __exclude(): array
    {
        return [
            'fake',
            'setDriver',
            'restoreDefaultDriver',
        ];
    }

    public static function __callback(): array
    {
        return [];
    }

    public static function fake(bool $available = true): FakeOpcodeDriver
    {
        return ObjectPortal1::fake($available);
    }

    public static function setDriver(?OpcodeDriverInterface $driver): void
    {
        ObjectPortal1::setDriver($driver);
    }

    public static function restoreDefaultDriver(): void
    {
        ObjectPortal1::restoreDefaultDriver();
    }
}
