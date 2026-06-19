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

        $normalized = str_replace('\\', '/', $reference);
        if (str_starts_with($normalized, '/')
            || preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            return $normalized;
        }

        if (function_exists('path')) {
            return path($reference);
        }

        return SystemConfig::path('storage') . '/' . ltrim($reference, '/');
    }
}
