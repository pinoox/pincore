<?php

namespace Pinoox\Component\Server\Share;

final class ShareWizardResult
{
    public function __construct(
        public readonly string $provider,
        public readonly string $mode,
        public readonly bool $network = false,
        public readonly ?string $password = null,
        public readonly ?string $expire = null,
        public readonly ?string $target = null,
        public readonly ?string $app = null,
    ) {
    }
}
