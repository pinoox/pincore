<?php

declare(strict_types=1);

namespace Pinoox\Component\Doctor;

use Symfony\Component\Console\Style\SymfonyStyle;

final class DoctorPresenter
{
    public function __construct(
        private readonly string $title = 'Pinoox Doctor',
        private readonly string $failureMessage = 'Health check failed — fix issues above before deploy or production use.',
        private readonly string $successMessage = 'All checks passed — environment is ready for development.',
    ) {
    }

    public function render(
        SymfonyStyle $io,
        DoctorReport $report,
        string $scopeLabel,
        string $root,
        bool $showFixes = true,
    ): void {
        $io->title($this->title);
        $this->renderHeader($io, $scopeLabel, $root, $report);

        foreach ($report->groups() as $group) {
            $items = $report->forGroup($group);

            if ($items === []) {
                continue;
            }

            $io->section($group);
            $io->table(
                ['', 'Check', 'Details'],
                array_map(
                    static fn (CheckItem $item): array => [
                        $item->status->icon(),
                        $item->label,
                        self::formatDetail($item),
                    ],
                    $items,
                ),
            );
        }

        $this->renderSummary($io, $report);

        if ($showFixes && $report->fixHints() !== []) {
            $io->section('Suggested fixes');
            $io->listing($report->fixHints());
        }

        if (!$report->isHealthy()) {
            $io->error($this->failureMessage);

            return;
        }

        if ($report->warnCount() > 0) {
            $io->warning('No blocking issues. Review warnings and suggested fixes before production.');

            return;
        }

        $io->success($this->successMessage);
    }

    private function renderHeader(SymfonyStyle $io, string $scopeLabel, string $root, DoctorReport $report): void
    {
        $score = $report->score();
        $bar = $this->scoreBar($score);
        $status = match (true) {
            $report->failCount() > 0 => '<fg=red;options=bold>Needs attention</>',
            $report->warnCount() > 0 => '<fg=yellow;options=bold>Mostly ready</>',
            default => '<fg=green;options=bold>Healthy</>',
        };

        $io->definitionList(
            ['Scope' => '<info>' . $scopeLabel . '</info>'],
            ['Root' => $root],
            ['Health' => $status . '  ' . $bar . '  <info>' . $score . '%</info>'],
            ['Checks' => sprintf(
                '<fg=green>%d passed</> · <fg=yellow>%d warnings</> · <fg=red>%d failed</>',
                $report->passCount(),
                $report->warnCount(),
                $report->failCount(),
            )],
        );

        $io->newLine();
    }

    private function renderSummary(SymfonyStyle $io, DoctorReport $report): void
    {
        $io->section('Summary');

        $io->table(['Metric', 'Value'], [
            ['Passed', (string) $report->passCount()],
            ['Warnings', (string) $report->warnCount()],
            ['Failed', (string) $report->failCount()],
            ['Health score', $report->score() . '%'],
        ]);

        if ($report->isHealthy() && $report->warnCount() === 0) {
            $io->text('  <fg=green>✔</> All scored checks passed.');
        } elseif ($report->isHealthy()) {
            $io->text('  <fg=yellow>!</> No blocking issues, but review warnings before production.');
        } else {
            $io->text('  <fg=red>✖</> Fix failed checks before continuing.');
        }
    }

    private function scoreBar(int $score): string
    {
        $filled = max(0, min(10, (int) round($score / 10)));
        $empty = 10 - $filled;
        $color = match (true) {
            $score >= 90 => 'green',
            $score >= 70 => 'yellow',
            default => 'red',
        };

        return '<fg=' . $color . '>' . str_repeat('█', $filled) . '</>'
            . '<fg=gray>' . str_repeat('░', $empty) . '</>';
    }

    private static function formatDetail(CheckItem $item): string
    {
        if ($item->detail === '') {
            return '';
        }

        if ($item->status === CheckStatus::Fail) {
            return '<fg=red>' . $item->detail . '</>';
        }

        if ($item->status === CheckStatus::Warn) {
            return '<fg=yellow>' . $item->detail . '</>';
        }

        return $item->detail;
    }
}
