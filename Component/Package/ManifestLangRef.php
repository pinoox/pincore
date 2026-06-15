<?php

namespace Pinoox\Component\Package;

/**
 * Lang key reference in app.php / theme.php manifest fields.
 *
 * @example '@manifest.title'
 * @example '@com_my_shop:manifest.description'
 */
final class ManifestLangRef
{
    public static function isRef(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, '@') && strlen($value) > 1;
    }

    /**
     * @return array{package: ?string, key: string}
     */
    public static function parse(string $ref): array
    {
        $ref = ltrim($ref, '@');

        if ($ref === '') {
            return ['package' => null, 'key' => ''];
        }

        if (str_contains($ref, ':')) {
            [$package, $key] = explode(':', $ref, 2);

            return [
                'package' => trim($package) !== '' ? trim($package) : null,
                'key' => trim($key),
            ];
        }

        return ['package' => null, 'key' => $ref];
    }
}
