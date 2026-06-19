<?php

namespace Pinoox\Component\Date;

use Carbon\Carbon;
use DateTimeZone;
use Pinoox\Component\Date\Contract\CalendarDateInterface;
use Pinoox\Component\Date\Internal\JalaliEngine;
use Pinoox\Portal\Config;

class JalaliDate implements CalendarDateInterface
{
    public function __construct(
        private readonly JalaliEngine $engine,
    ) {
    }

    public static function now(DateTimeZone|string|null $timezone = null): self
    {
        return new self(JalaliEngine::now(self::resolveTimezone($timezone)));
    }

    public static function make(mixed $time = null, DateTimeZone|string|null $timezone = null): self
    {
        return new self(JalaliEngine::from($time, self::resolveTimezone($timezone)));
    }

    public static function parse(string $date, string $format = 'Y-m-d', DateTimeZone|string|null $timezone = null): self
    {
        return new self(JalaliEngine::parse($date, $format, self::resolveTimezone($timezone)));
    }

    public function calendar(): string
    {
        return 'jalali';
    }

    public function format(string $format): string
    {
        return $this->engine->format($format);
    }

    public function formatKey(string $key): string
    {
        $formats = Config::name('~date')->get('formats.jalali', []);

        return $this->format((string) ($formats[$key] ?? 'Y/m/d H:i:s'));
    }

    public function toCarbon(): Carbon
    {
        return $this->engine->toCarbon();
    }

    public function toGregorian(string $format = 'Y-m-d H:i:s'): string
    {
        return $this->toCarbon()->format($format);
    }

    public function timestamp(): int
    {
        return $this->engine->timestamp();
    }

    public function dayOfWeek(): int
    {
        return $this->engine->dayOfWeek();
    }

    public function ago(): string
    {
        return DateFormatter::approximate($this->toCarbon(), calendar: 'jalali');
    }

    public function diffForHumans(?Carbon $other = null): string
    {
        return DateFormatter::approximate($this->toCarbon(), $other, calendar: 'jalali');
    }

    public function addDays(int $days): static
    {
        return new self($this->engine->addDays($days));
    }

    public function subDays(int $days): static
    {
        return new self($this->engine->subDays($days));
    }

    public function addMonths(int $months): static
    {
        return new self($this->engine->addMonths($months));
    }

    public function subMonths(int $months): static
    {
        return new self($this->engine->subMonths($months));
    }

    public function addYears(int $years): static
    {
        return new self($this->engine->addYears($years));
    }

    public function subYears(int $years): static
    {
        return new self($this->engine->subYears($years));
    }

    public function copy(): static
    {
        return new self(JalaliEngine::from($this->toCarbon()));
    }

    public function isToday(): bool
    {
        return $this->toCarbon()->isToday();
    }

    public function isPast(): bool
    {
        return $this->toCarbon()->isPast();
    }

    public function isFuture(): bool
    {
        return $this->toCarbon()->isFuture();
    }

    public function eq(mixed $other): bool
    {
        return $this->toCarbon()->equalTo(self::resolveComparable($other));
    }

    public function gt(mixed $other): bool
    {
        return $this->toCarbon()->greaterThan(self::resolveComparable($other));
    }

    public function lt(mixed $other): bool
    {
        return $this->toCarbon()->lessThan(self::resolveComparable($other));
    }

    public function __call(string $name, array $arguments): mixed
    {
        $result = $this->engine->call($name, $arguments);

        return $result instanceof JalaliEngine ? new self($result) : $result;
    }

    public function __toString(): string
    {
        return $this->engine->format('Y/m/d H:i:s');
    }

    private static function resolveComparable(mixed $other): Carbon
    {
        if ($other instanceof CalendarDateInterface) {
            return $other->toCarbon();
        }

        if ($other instanceof Carbon) {
            return $other;
        }

        return Carbon::parse($other);
    }

    private static function resolveTimezone(DateTimeZone|string|null $timezone): ?DateTimeZone
    {
        if ($timezone instanceof DateTimeZone) {
            return $timezone;
        }

        if (is_string($timezone) && $timezone !== '') {
            return new DateTimeZone($timezone);
        }

        return null;
    }
}
