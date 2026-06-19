<?php

namespace Pinoox\Component\Date;

use Carbon\Carbon;
use DateTimeZone;
use Pinoox\Component\Date\Contract\CalendarDateInterface;
use Pinoox\Portal\Config;

final class GregorianDate implements CalendarDateInterface
{
    public function __construct(
        private Carbon $inner,
    ) {
    }

    public static function make(mixed $time = null, DateTimeZone|string|null $timezone = null): self
    {
        if ($time instanceof self) {
            return $time;
        }

        if ($time instanceof Carbon) {
            return new self($time->copy());
        }

        $tz = self::resolveTimezoneString($timezone);

        return new self(Carbon::parse($time ?? 'now', $tz));
    }

    public function calendar(): string
    {
        return 'gregorian';
    }

    public function format(string $format): string
    {
        return $this->inner->format($format);
    }

    public function formatKey(string $key): string
    {
        $formats = Config::name('~date')->get('formats.gregorian', []);

        return $this->format((string) ($formats[$key] ?? 'Y-m-d H:i:s'));
    }

    public function timestamp(): int
    {
        return $this->inner->getTimestamp();
    }

    public function toCarbon(): Carbon
    {
        return $this->inner->copy();
    }

    public function toGregorian(string $format = 'Y-m-d H:i:s'): string
    {
        return $this->inner->format($format);
    }

    public function ago(): string
    {
        return DateFormatter::approximate($this->inner, calendar: 'gregorian');
    }

    public function diffForHumans(?Carbon $other = null): string
    {
        return DateFormatter::approximate($this->inner, $other, calendar: 'gregorian');
    }

    public function addDays(int $days): static
    {
        return new self($this->inner->copy()->addDays($days));
    }

    public function subDays(int $days): static
    {
        return new self($this->inner->copy()->subDays($days));
    }

    public function addMonths(int $months): static
    {
        return new self($this->inner->copy()->addMonths($months));
    }

    public function subMonths(int $months): static
    {
        return new self($this->inner->copy()->subMonths($months));
    }

    public function addYears(int $years): static
    {
        return new self($this->inner->copy()->addYears($years));
    }

    public function subYears(int $years): static
    {
        return new self($this->inner->copy()->subYears($years));
    }

    public function copy(): static
    {
        return new self($this->inner->copy());
    }

    public function isToday(): bool
    {
        return $this->inner->isToday();
    }

    public function isPast(): bool
    {
        return $this->inner->isPast();
    }

    public function isFuture(): bool
    {
        return $this->inner->isFuture();
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
        $result = $this->inner->{$name}(...$arguments);

        return $result instanceof Carbon ? new self($result) : $result;
    }

    public function __toString(): string
    {
        return $this->inner->format('Y-m-d H:i:s');
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

    private static function resolveTimezoneString(DateTimeZone|string|null $timezone): ?string
    {
        if ($timezone instanceof DateTimeZone) {
            return $timezone->getName();
        }

        return is_string($timezone) && $timezone !== '' ? $timezone : null;
    }
}
