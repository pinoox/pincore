<?php

namespace Pinoox\Component\Date;

use Pinoox\Portal\App\App;

/**
 * App-level date settings from app.php.
 *
 * Supported forms in app.php:
 *
 *   'date' => 'jalali',
 *   'date' => 'gregorian',
 *   'date' => ['calendar' => 'jalali', 'timezone' => 'Asia/Tehran'],
 *   'calendar' => 'jalali',
 *   'timezone' => 'Asia/Tehran',
 */
final class AppDateConfig
{
    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public static function normalizeManifest(array $manifest): array
    {
        $date = $manifest['date'] ?? null;

        if (is_string($date) && trim($date) !== '') {
            $manifest['date'] = [
                'calendar' => self::normalizeCalendar($date),
                'timezone' => null,
            ];
        } elseif (!is_array($manifest['date'] ?? null)) {
            $manifest['date'] = [
                'calendar' => null,
                'timezone' => null,
            ];
        } else {
            $manifest['date'] = array_replace(
                ['calendar' => null, 'timezone' => null],
                $manifest['date'],
            );
        }

        if (isset($manifest['calendar']) && is_string($manifest['calendar']) && trim($manifest['calendar']) !== '') {
            if (empty($manifest['date']['calendar'])) {
                $manifest['date']['calendar'] = self::normalizeCalendar($manifest['calendar']);
            }

            unset($manifest['calendar']);
        }

        if (isset($manifest['timezone']) && is_string($manifest['timezone']) && trim($manifest['timezone']) !== '') {
            if (empty($manifest['date']['timezone'])) {
                $manifest['date']['timezone'] = trim($manifest['timezone']);
            }

            unset($manifest['timezone']);
        }

        if (is_string($manifest['date']['calendar'] ?? null) && $manifest['date']['calendar'] !== '') {
            $manifest['date']['calendar'] = self::normalizeCalendar($manifest['date']['calendar']);
        }

        return $manifest;
    }

    public static function calendarFromApp(): ?string
    {
        try {
            $value = App::get('date.calendar');

            if (is_string($value) && trim($value) !== '') {
                return self::normalizeCalendar($value);
            }
        } catch (\Throwable) {
        }

        return null;
    }

    public static function timezoneFromApp(): ?string
    {
        try {
            $value = App::get('date.timezone');

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        } catch (\Throwable) {
        }

        return null;
    }

    public static function normalizeCalendar(string $calendar): string
    {
        return match (strtolower(trim($calendar))) {
            'jalali', 'jalaali', 'shamsi' => 'jalali',
            'gregorian', 'gregory', 'miladi', 'g' => 'gregorian',
            default => 'gregorian',
        };
    }
}
