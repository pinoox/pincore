<?php

declare(strict_types=1);

namespace Pinoox\Component\Doctor;

use Pinoox\Support\DevApp;

final class DoctorProject
{
    public const TYPE_PLATFORM = 'platform';

    public const TYPE_SINGLE_APP = 'single-app';

    public function __construct(
        public readonly string $root,
        public readonly string $type,
        public readonly string $package,
    ) {
    }

    public function isPlatformProject(): bool
    {
        return $this->type === self::TYPE_PLATFORM;
    }

    public function isSingleApp(): bool
    {
        return $this->type === self::TYPE_SINGLE_APP;
    }

    public function isPlatformScope(): bool
    {
        return $this->isPlatformProject() && $this->package === 'platform';
    }

    public static function resolve(string $root, ?string $package = null): self
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');

        if (is_dir($root . '/apps')) {
            $package = self::normalizePackage($package) ?? DevApp::defaultCliPackage();

            return new self($root, self::TYPE_PLATFORM, $package);
        }

        $detected = DevApp::package($root) ?? self::packageFromRootApp($root);
        $package = self::normalizePackage($package) ?? $detected ?? 'app';

        return new self($root, self::TYPE_SINGLE_APP, $package);
    }

    private static function normalizePackage(?string $package): ?string
    {
        if ($package === null) {
            return null;
        }

        $package = trim($package);

        return $package !== '' ? $package : null;
    }

    private static function packageFromRootApp(string $root): ?string
    {
        $appFile = $root . '/app.php';

        if (!is_file($appFile)) {
            return null;
        }

        $config = require $appFile;

        if (!is_array($config)) {
            return null;
        }

        $package = $config['package'] ?? null;

        return is_string($package) && $package !== '' ? $package : null;
    }
}
