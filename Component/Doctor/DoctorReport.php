<?php

declare(strict_types=1);

namespace Pinoox\Component\Doctor;

final class DoctorReport
{
    /** @var list<CheckItem> */
    private array $items = [];

    public function add(CheckItem $item): void
    {
        $this->items[] = $item;
    }

    /**
     * @return list<CheckItem>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @return list<CheckItem>
     */
    public function forGroup(string $group): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (CheckItem $item): bool => $item->group === $group,
        ));
    }

    /**
     * @return list<string>
     */
    public function groups(): array
    {
        $groups = [];

        foreach ($this->items as $item) {
            $groups[$item->group] = true;
        }

        return array_keys($groups);
    }

    public function failCount(): int
    {
        return $this->countByStatus(CheckStatus::Fail);
    }

    public function warnCount(): int
    {
        return $this->countByStatus(CheckStatus::Warn);
    }

    public function passCount(): int
    {
        return $this->countByStatus(CheckStatus::Pass);
    }

    public function score(): int
    {
        $total = 0.0;
        $earned = 0.0;

        foreach ($this->items as $item) {
            if (!$item->scored) {
                continue;
            }

            $total += 1.0;
            $earned += $item->status->weight();
        }

        if ($total === 0.0) {
            return 0;
        }

        return (int) round(($earned / $total) * 100);
    }

    public function isHealthy(): bool
    {
        return $this->failCount() === 0;
    }

    /**
     * @return list<string>
     */
    public function fixHints(): array
    {
        $hints = [];

        foreach ($this->items as $item) {
            if ($item->hint === null || $item->hint === '') {
                continue;
            }

            if ($item->status === CheckStatus::Fail || $item->status === CheckStatus::Warn) {
                $hints[] = $item->hint;
            }
        }

        return array_values(array_unique($hints));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?array $appMeta = null): array
    {
        return [
            'healthy' => $this->isHealthy(),
            'score' => $this->score(),
            'summary' => [
                'pass' => $this->passCount(),
                'warn' => $this->warnCount(),
                'fail' => $this->failCount(),
            ],
            'app' => $appMeta,
            'checks' => array_map(static fn (CheckItem $item): array => $item->toArray(), $this->items),
            'fixes' => $this->fixHints(),
        ];
    }

    private function countByStatus(CheckStatus $status): int
    {
        return count(array_filter(
            $this->items,
            static fn (CheckItem $item): bool => $item->status === $status,
        ));
    }
}
