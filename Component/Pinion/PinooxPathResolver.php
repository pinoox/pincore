<?php

namespace Pinoox\Component\Pinion;

use Pinoox\Pinion\Contract\PathResolverInterface;
use Pinoox\Support\SystemConfig;

final class PinooxPathResolver implements PathResolverInterface
{
    public function resolve(string $reference): string
    {
        if (str_starts_with($reference, '~pinion/')) {
            $base = SystemConfig::path('pinion_uploads');

            return rtrim($base, '/\\') . '/' . ltrim(substr($reference, strlen('~pinion/')), '/');
        }

        if (str_starts_with($reference, 'local:')) {
            $reference = substr($reference, strlen('local:'));
        }

        if (function_exists('path')) {
            return path($reference);
        }

        return SystemConfig::path('storage') . '/' . ltrim($reference, '/');
    }
}
