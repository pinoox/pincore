<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Portal\Lang;

/**
 * Format pinx manifest metadata for CLI output.
 */
final class PinxCliManifest
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function summaryRows(PinxManifest $manifest, ?string $locale = null): array
    {
        $locale ??= self::cliLocale();

        return [
            ['Name', $manifest->title($locale)],
            ['Description', $manifest->description($locale) ?: '—'],
        ];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function labelRows(PinxManifest $manifest): array
    {
        $rows = [];

        foreach ($manifest->labels() as $field => $map) {
            if (!is_array($map) || $map === []) {
                continue;
            }

            foreach ($map as $locale => $value) {
                if (!is_string($value) || $value === '') {
                    continue;
                }

                $rows[] = [ucfirst($field) . ' [' . $locale . ']', $value];
            }
        }

        return $rows;
    }

    private static function cliLocale(): string
    {
        try {
            return (string) Lang::locale();
        } catch (\Throwable) {
            return 'en';
        }
    }
}
