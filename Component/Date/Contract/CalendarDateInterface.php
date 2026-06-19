<?php

namespace Pinoox\Component\Date\Contract;

use Carbon\Carbon;

interface CalendarDateInterface
{
    public function calendar(): string;

    public function format(string $format): string;

    public function formatKey(string $key): string;

    public function timestamp(): int;

    public function toCarbon(): Carbon;

    public function toGregorian(string $format = 'Y-m-d H:i:s'): string;

    public function ago(): string;

    public function diffForHumans(?Carbon $other = null): string;

    public function addDays(int $days): static;

    public function subDays(int $days): static;

    public function addMonths(int $months): static;

    public function subMonths(int $months): static;

    public function addYears(int $years): static;

    public function subYears(int $years): static;

    public function copy(): static;

    public function isToday(): bool;

    public function isPast(): bool;

    public function isFuture(): bool;

    public function eq(mixed $other): bool;

    public function gt(mixed $other): bool;

    public function lt(mixed $other): bool;
}
