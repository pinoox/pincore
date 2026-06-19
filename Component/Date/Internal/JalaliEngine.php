<?php

namespace Pinoox\Component\Date\Internal;

use Carbon\Carbon;
use DateTimeInterface;
use DateTimeZone;
use Morilog\Jalali\Jalalian;

/**
 * Single integration point for morilog/jalali inside Pinoox core.
 */
final class JalaliEngine
{
    public function __construct(
        private readonly Jalalian $inner,
    ) {
    }

    public static function now(?DateTimeZone $timezone = null): self
    {
        return new self(Jalalian::now($timezone));
    }

    public static function from(mixed $time, ?DateTimeZone $timezone = null): self
    {
        if ($time instanceof self) {
            return $time;
        }

        if ($time instanceof Jalalian) {
            return new self($time);
        }

        if ($time === null || $time === 'now') {
            return self::now($timezone);
        }

        if ($time instanceof Carbon) {
            return new self(Jalalian::fromCarbon($time));
        }

        if ($time instanceof DateTimeInterface) {
            return new self(Jalalian::fromDateTime($time, $timezone));
        }

        if (is_int($time)) {
            return new self(Jalalian::fromDateTime($time, $timezone));
        }

        return new self(Jalalian::fromDateTime((string) $time, $timezone));
    }

    public static function parse(string $date, string $format = 'Y-m-d', ?DateTimeZone $timezone = null): self
    {
        return new self(Jalalian::fromFormat($format, $date, $timezone));
    }

    public function format(string $format): string
    {
        $formatted = $this->inner->format($format);

        if ($formatted === '' || mb_check_encoding($formatted, 'UTF-8')) {
            return $formatted;
        }

        return (string) mb_convert_encoding($formatted, 'UTF-8', 'UTF-8');
    }

    public function toCarbon(): Carbon
    {
        return $this->inner->toCarbon();
    }

    public function timestamp(): int
    {
        return $this->inner->getTimestamp();
    }

    public function dayOfWeek(): int
    {
        return $this->inner->getDayOfWeek();
    }

    public function addDays(int $days): self
    {
        return new self($this->inner->addDays($days));
    }

    public function subDays(int $days): self
    {
        return new self($this->inner->subDays($days));
    }

    public function addMonths(int $months): self
    {
        return new self($this->inner->addMonths($months));
    }

    public function subMonths(int $months): self
    {
        return new self($this->inner->subMonths($months));
    }

    public function addYears(int $years): self
    {
        return new self($this->inner->addYears($years));
    }

    public function subYears(int $years): self
    {
        return new self($this->inner->subYears($years));
    }

    public function call(string $name, array $arguments): mixed
    {
        $result = $this->inner->{$name}(...$arguments);

        return $result instanceof Jalalian ? new self($result) : $result;
    }
}
