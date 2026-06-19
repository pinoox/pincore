<?php

namespace Pinoox\Tests\Feature\Date;

use PHPUnit\Framework\TestCase;
use Pinoox\Component\Date\AppDateConfig;

final class AppDateConfigTest extends TestCase
{
    public function test_string_shorthand_expands_to_date_block(): void
    {
        $normalized = AppDateConfig::normalizeManifest([
            'package' => 'com_example_app',
            'date' => 'jalali',
        ]);

        self::assertSame('jalali', $normalized['date']['calendar']);
        self::assertNull($normalized['date']['timezone']);
    }

    public function test_root_calendar_alias_merges_into_date(): void
    {
        $normalized = AppDateConfig::normalizeManifest([
            'calendar' => 'shamsi',
        ]);

        self::assertSame('jalali', $normalized['date']['calendar']);
        self::assertArrayNotHasKey('calendar', $normalized);
    }

    public function test_root_timezone_alias_merges_into_date(): void
    {
        $normalized = AppDateConfig::normalizeManifest([
            'timezone' => 'Asia/Tehran',
        ]);

        self::assertSame('Asia/Tehran', $normalized['date']['timezone']);
        self::assertArrayNotHasKey('timezone', $normalized);
    }

    public function test_explicit_date_block_is_preserved(): void
    {
        $normalized = AppDateConfig::normalizeManifest([
            'date' => [
                'calendar' => 'gregorian',
                'timezone' => 'UTC',
            ],
        ]);

        self::assertSame('gregorian', $normalized['date']['calendar']);
        self::assertSame('UTC', $normalized['date']['timezone']);
    }

    public function test_root_aliases_do_not_override_explicit_date_values(): void
    {
        $normalized = AppDateConfig::normalizeManifest([
            'calendar' => 'jalali',
            'timezone' => 'Asia/Tehran',
            'date' => [
                'calendar' => 'gregorian',
                'timezone' => 'UTC',
            ],
        ]);

        self::assertSame('gregorian', $normalized['date']['calendar']);
        self::assertSame('UTC', $normalized['date']['timezone']);
    }

    public function test_normalize_calendar_aliases(): void
    {
        self::assertSame('jalali', AppDateConfig::normalizeCalendar('shamsi'));
        self::assertSame('gregorian', AppDateConfig::normalizeCalendar('miladi'));
    }
}
