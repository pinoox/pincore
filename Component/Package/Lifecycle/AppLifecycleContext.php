<?php

namespace Pinoox\Component\Package\Lifecycle;

final class AppLifecycleContext
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly string $package,
        public readonly string $action,
        public readonly ?int $fromVersionCode = null,
        public readonly ?int $toVersionCode = null,
        public readonly ?string $fromVersionName = null,
        public readonly ?string $toVersionName = null,
        public readonly string $appPath = '',
        public readonly array $extra = [],
    ) {
    }
}
