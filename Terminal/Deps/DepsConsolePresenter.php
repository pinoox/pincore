<?php

namespace Pinoox\Terminal\Deps;

use Pinoox\Component\Deps\DependencyOutputFilter;
use Pinoox\Component\Deps\DependencyRunResult;
use Pinoox\Component\Deps\DependencyTarget;
use Pinoox\Component\Helpers\ConsoleApplication as ConsoleApplicationHelper;
use Pinoox\Support\SystemConfig;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class DepsConsolePresenter
{
    private readonly DependencyOutputFilter $filter;

    private string $projectRoot;

    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly OutputInterface $output,
        private readonly bool $plain = false,
    ) {
        $this->filter = new DependencyOutputFilter();
        $this->projectRoot = SystemConfig::rootPath();
    }

    /**
     * @param list<DependencyTarget> $targets
     */
    public function renderHeader(string $action, string $scope, array $targets): void
    {
        $composerCount = count(array_filter($targets, static fn (DependencyTarget $target): bool => $target->type === 'composer'));
        $npmCount = count(array_filter($targets, static fn (DependencyTarget $target): bool => $target->type === 'npm'));
        $missingCount = count(array_filter($targets, static fn (DependencyTarget $target): bool => !$target->isInstalled()));

        if ($this->isPlain()) {
            $this->io->title('Pinoox Dependencies — ' . ucfirst($action));
            $this->io->text([
                'Scope: <info>' . $scope . '</info>',
                'Targets: <info>' . count($targets) . '</info> (composer: ' . $composerCount . ', npm: ' . $npmCount . ')',
                'Missing: <info>' . $missingCount . '</info>',
            ]);

            return;
        }

        $this->io->writeln('');
        $this->io->writeln('  <fg=cyan;options=bold>╭──────────────────────────────────────────────────────────────────────────╮</>');
        $this->io->writeln('  <fg=cyan;options=bold>│</> <fg=white;options=bold>Pinoox Dependencies</>                                                      <fg=cyan;options=bold>│</>');
        $this->io->writeln('  <fg=cyan;options=bold>│</> Action: <info>' . $this->padLabel(ucfirst($action), 12) . '</info> Scope: <info>' . $this->padLabel($scope, 20) . '</info> Targets: <info>' . count($targets) . '</info>   <fg=cyan;options=bold>│</>');
        $this->io->writeln('  <fg=cyan;options=bold>│</> Composer: <info>' . $this->padLabel((string) $composerCount, 10) . '</info> npm: <info>' . $this->padLabel((string) $npmCount, 10) . '</info> Missing: <info>' . $this->padLabel((string) $missingCount, 10) . '</info>             <fg=cyan;options=bold>│</>');
        $this->io->writeln('  <fg=cyan;options=bold>╰──────────────────────────────────────────────────────────────────────────╯</>');
        $this->io->writeln('');
    }

    /**
     * @param list<DependencyTarget> $targets
     */
    public function renderPlan(string $action, array $targets): void
    {
        $this->io->section('Execution plan');

        $rows = [];
        foreach ($targets as $index => $target) {
            $rows[] = [
                (string) ($index + 1),
                strtoupper($target->type),
                $target->scope,
                $this->shortLabel($target),
                $this->relativePath($target->path),
                $target->isInstalled() ? '<fg=green>ready</>' : '<fg=yellow>missing</>',
                '<fg=gray>pending</>',
            ];
        }

        $table = new Table($this->output);
        $table->setHeaders(['#', 'Type', 'Scope', 'Target', 'Path', 'State', 'Step']);
        $table->setRows($rows);
        $table->setStyle($this->tableStyle());
        $table->render();

        $this->io->newLine();
        $this->io->note('Each target runs in its own step. Completed steps collapse to a single summary line.');
    }

    /**
     * @param list<DependencyTarget> $targets
     * @return list<DependencyRunResult>
     */
    public function runWorkflow(
        string $action,
        array $targets,
        callable $runner,
        bool $continueOnError = false,
    ): array {
        $total = count($targets);
        $results = [];
        $progress = $this->createProgressBar($total);
        $progress?->start();

        foreach ($targets as $index => $target) {
            $step = $index + 1;
            $this->renderStepOpening($action, $step, $total, $target);

            $liveOutput = function (string $line) use ($target): void {
                $this->renderLiveLine($target->type, $line);
            };

            $result = $runner($target, $liveOutput);
            $results[] = $result;

            $progress?->advance();
            $this->renderStepClosing($step, $total, $result);

            if (!$result->succeeded() && !$continueOnError) {
                $progress?->finish();
                $this->io->newLine(2);
                $this->renderFailureSummary($results, $step, $total);

                return $results;
            }
        }

        $progress?->finish();
        $this->io->newLine(2);

        return $results;
    }

    /**
     * @param list<DependencyRunResult> $results
     */
    public function renderFinalSummary(string $action, array $results): int
    {
        $failed = array_values(array_filter($results, static fn (DependencyRunResult $result): bool => !$result->succeeded()));
        $passed = count($results) - count($failed);

        $this->io->section('Run summary');

        $rows = [];
        foreach ($results as $index => $result) {
            $target = $result->target;
            $rows[] = [
                (string) ($index + 1),
                strtoupper($target->type),
                $this->shortLabel($target),
                $this->resultStatusLabel($result),
                sprintf('%.1fs', $result->durationSeconds),
                $result->commandLine,
            ];
        }

        $table = new Table($this->output);
        $table->setHeaders(['#', 'Type', 'Target', 'Result', 'Time', 'Command']);
        $table->setRows($rows);
        $table->render();

        $totalSeconds = array_reduce(
            $results,
            static fn (float $carry, DependencyRunResult $result): float => $carry + $result->durationSeconds,
            0.0,
        );

        $this->io->newLine();
        $this->io->definitionList(
            ['Action' => ucfirst($action)],
            ['Succeeded' => sprintf('%d / %d', $passed, count($results))],
            ['Total time' => sprintf('%.1fs', $totalSeconds)],
        );

        $this->renderWarningsSummary($results);

        if ($failed !== []) {
            $this->io->error(sprintf('%d target(s) failed.', count($failed)));

            return 1;
        }

        $this->io->success(sprintf('All %d target(s) completed successfully.', count($results)));

        return 0;
    }

    /**
     * @param list<DependencyTarget> $targets
     */
    public function renderStatusBoard(array $targets): void
    {
        $this->io->section('Dependency inventory');

        $rows = [];
        $missing = 0;

        foreach ($targets as $index => $target) {
            $installed = $target->isInstalled();
            if (!$installed) {
                $missing++;
            }

            $rows[] = [
                (string) ($index + 1),
                strtoupper($target->type),
                $target->scope,
                $this->shortLabel($target),
                $this->relativePath($target->path),
                $this->manifestHint($target),
                $installed ? '<fg=green>installed</>' : '<fg=yellow>missing</>',
            ];
        }

        $table = new Table($this->output);
        $table->setHeaders(['#', 'Type', 'Scope', 'Target', 'Path', 'Manifest', 'Vendor']);
        $table->setRows($rows);
        $table->setStyle($this->tableStyle());
        $table->render();

        $this->io->newLine();
        $this->io->definitionList(
            ['Targets' => (string) count($targets)],
            ['Installed' => (string) (count($targets) - $missing)],
            ['Missing' => (string) $missing],
        );

        if ($missing > 0) {
            $scope = $targets[0]->scope ?? 'all';
            $invoke = getenv('PINOOX_CLI_INVOKE') === 'pinx'
                ? 'pinx deps:install'
                : 'php pinoox deps install';
            $this->io->note(sprintf(
                '%d target(s) are not installed yet. Run: <info>%s %s</info>',
                $missing,
                $invoke,
                $scope,
            ));
        } else {
            $this->io->success('All discovered dependency targets are installed.');
        }
    }

    private function manifestHint(DependencyTarget $target): string
    {
        $manifest = $target->manifestPath();

        if (!is_file($manifest)) {
            return '<fg=red>missing</>';
        }

        if ($target->type === 'npm') {
            $lock = $target->path . '/package-lock.json';

            return is_file($lock) ? '<fg=green>package.json + lock</>' : '<fg=yellow>package.json</>';
        }

        $lock = $target->path . '/composer.lock';

        return is_file($lock) ? '<fg=green>composer.json + lock</>' : '<fg=yellow>composer.json</>';
    }

    private function renderStepOpening(string $action, int $step, int $total, DependencyTarget $target): void
    {
        if ($this->isPlain()) {
            $this->io->section(sprintf('Step %d/%d — %s', $step, $total, $target->label));
            $this->io->text([
                'Path: <comment>' . $this->relativePath($target->path) . '</comment>',
                'Manifest: <comment>' . $this->relativePath($target->manifestPath()) . '</comment>',
            ]);

            return;
        }

        $this->io->writeln('');
        $this->io->writeln(sprintf(
            '  <fg=cyan;options=bold>┌─ Step %d/%d</> <fg=white;options=bold>%s</> <fg=gray>·</> <comment>%s</comment>',
            $step,
            $total,
            strtoupper($target->type),
            $this->shortLabel($target),
        ));
        $this->io->writeln('  <fg=cyan>│</>  <fg=gray>action</>   <info>' . $action . '</info>');
        $this->io->writeln('  <fg=cyan>│</>  <fg=gray>path</>     <comment>' . $this->relativePath($target->path) . '</comment>');
        $this->io->writeln('  <fg=cyan>│</>  <fg=gray>manifest</> <comment>' . $this->relativePath($target->manifestPath()) . '</comment>');
        $this->io->writeln('  <fg=cyan>│</>  <fg=gray>state</>    ' . ($target->isInstalled() ? '<fg=green>dependencies present</>' : '<fg=yellow>dependencies missing</>'));
        $this->io->writeln('  <fg=cyan>├─</> <fg=yellow;options=bold>running...</>');
        $this->io->writeln('  <fg=cyan>│</>');
    }

    private function renderLiveLine(string $type, string $line): void
    {
        if (!$this->filter->shouldDisplay($line, $type, $this->output->isVerbose())) {
            return;
        }

        $prefix = $this->isPlain() ? '  ' : '  <fg=cyan>│</>  ';

        if ($this->looksLikeErrorLine($line)) {
            $this->io->writeln($prefix . '<fg=red>' . $this->escapeOutput($line) . '</>');
            return;
        }

        if ($this->looksLikeWarningLine($line)) {
            $this->io->writeln($prefix . '<fg=yellow>' . $this->escapeOutput($line) . '</>');
            return;
        }

        $this->io->writeln($prefix . '<fg=gray>' . $this->escapeOutput($line) . '</>');
    }

    private function renderStepClosing(int $step, int $total, DependencyRunResult $result): void
    {
        if ($this->isPlain()) {
            if ($result->succeeded()) {
                $label = $result->hasWarnings()
                    ? sprintf('Step %d/%d completed with warnings in %.1fs (fallback)', $step, $total, $result->durationSeconds)
                    : sprintf('Step %d/%d completed in %.1fs', $step, $total, $result->durationSeconds);
                $this->io->success($label);
                foreach ($result->warnings as $warning) {
                    $this->io->writeln('  <fg=yellow>' . $this->escapeOutput($warning) . '</>');
                }
            } else {
                $this->io->error(sprintf(
                    'Step %d/%d failed in %.1fs',
                    $step,
                    $total,
                    $result->durationSeconds,
                ));
            }

            return;
        }

        if ($result->succeeded()) {
            $this->io->writeln('  <fg=cyan>└─</> <fg=green;options=bold>✔ completed</> <fg=gray>in</> <info>' . sprintf('%.1fs', $result->durationSeconds) . '</info> <fg=gray>·</> <comment>' . $this->escapeOutput($result->commandLine) . '</comment>');
            if ($result->hasWarnings()) {
                $this->io->writeln(sprintf(
                    '  <fg=yellow>▸</> <fg=gray>[%d/%d]</> <fg=yellow>done (fallback)</> <comment>%s</comment>',
                    $step,
                    $total,
                    $this->shortLabel($result->target),
                ));
                foreach ($result->warnings as $warning) {
                    $this->io->writeln('  <fg=yellow>│</>  <fg=yellow>' . $this->escapeOutput($warning) . '</>');
                }
            } else {
                $this->io->writeln(sprintf(
                    '  <fg=green>▸</> <fg=gray>[%d/%d]</> <fg=green>done</> <comment>%s</comment>',
                    $step,
                    $total,
                    $this->shortLabel($result->target),
                ));
            }
            return;
        }

        $this->io->writeln('  <fg=cyan>└─</> <fg=red;options=bold>✖ failed</> <fg=gray>in</> <info>' . sprintf('%.1fs', $result->durationSeconds) . '</info> <fg=gray>·</> <comment>' . $this->escapeOutput($result->commandLine) . '</comment>');
        $this->io->writeln(sprintf(
            '  <fg=red>▸</> <fg=gray>[%d/%d]</> <fg=red>failed</> <comment>%s</comment>',
            $step,
            $total,
            $this->shortLabel($result->target),
        ));

        $tail = $this->tailLines($result->outputLines, 6);
        if ($tail !== []) {
            $this->io->writeln('  <fg=red>│</>  <fg=red;options=bold>last output</>');
            foreach ($tail as $line) {
                $this->io->writeln('  <fg=red>│</>  <fg=red>' . $this->escapeOutput($line) . '</>');
            }
        }
    }

    /**
     * @param list<DependencyRunResult> $results
     */
    private function renderWarningsSummary(array $results): void
    {
        $warned = array_values(array_filter(
            $results,
            static fn (DependencyRunResult $result): bool => $result->hasWarnings(),
        ));

        if ($warned === []) {
            return;
        }

        $this->io->newLine();
        $this->io->section('Notes / Warnings');

        foreach ($warned as $result) {
            $this->io->writeln(sprintf(
                '  <fg=yellow>•</> <comment>%s</comment>',
                $this->escapeOutput($this->shortLabel($result->target)),
            ));
            foreach ($result->warnings as $warning) {
                $this->io->writeln('    <fg=yellow>' . $this->escapeOutput($warning) . '</>');
            }
        }

        $this->io->newLine();
        $this->io->note('npm ci fell back to npm install for one or more targets. Commit updated package-lock.json files so the next install can use a clean npm ci.');
    }

    private function resultStatusLabel(DependencyRunResult $result): string
    {
        if (!$result->succeeded()) {
            return '<fg=red>failed</>';
        }

        if ($result->hasWarnings()) {
            return '<fg=yellow>done (fallback)</>';
        }

        return '<fg=green>done</>';
    }

    /**
     * @param list<DependencyRunResult> $results
     */
    private function renderFailureSummary(array $results, int $failedStep, int $total): void
    {
        $this->io->section('Stopped early');
        $this->io->warning(sprintf(
            'Step %d/%d failed. Remaining %d target(s) were skipped.',
            $failedStep,
            $total,
            max(0, $total - count($results)),
        ));
    }

    private function createProgressBar(int $max): ?ProgressBar
    {
        if ($this->isPlain() || $max <= 1) {
            return null;
        }

        $progress = new ProgressBar($this->output, $max);
        $progress->setFormat(
            "  <fg=cyan>progress</> %current%/%max% [%bar%] %percent:3s%%\n",
        );

        if ($this->prefersAscii()) {
            $progress->setBarCharacter('#');
            $progress->setEmptyBarCharacter('-');
            $progress->setProgressCharacter('#');
        } else {
            $progress->setBarCharacter('█');
            $progress->setEmptyBarCharacter('░');
            $progress->setProgressCharacter('█');
        }

        return $progress;
    }

    private function shortLabel(DependencyTarget $target): string
    {
        return str_replace(' (composer)', '', str_replace(' (npm)', '', $target->label));
    }

    private function relativePath(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $this->projectRoot), '/');

        if ($path === $root) {
            return '.';
        }

        if (str_starts_with($path, $root . '/')) {
            return ltrim(substr($path, strlen($root)), '/');
        }

        return $path;
    }

    private function padLabel(string $value, int $width): string
    {
        $value = mb_strlen($value) > $width ? mb_substr($value, 0, $width - 1) . '…' : $value;

        return str_pad($value, $width);
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function tailLines(array $lines, int $limit): array
    {
        $filtered = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));

        if ($filtered === []) {
            return [];
        }

        return array_slice($filtered, -$limit);
    }

    private function looksLikeErrorLine(string $line): bool
    {
        $lower = strtolower($line);

        if ($this->looksLikeWarningLine($line)) {
            return false;
        }

        foreach (['error', 'failed', 'fatal', 'npm err'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeWarningLine(string $line): bool
    {
        $lower = strtolower(trim($line));

        return str_starts_with($lower, 'warning:')
            || str_starts_with($lower, 'npm warn')
            || str_contains($lower, 'falling back to npm install')
            || str_contains($lower, 'out of sync');
    }

    private function escapeOutput(string $line): string
    {
        return htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function isPlain(): bool
    {
        return $this->plain || $this->prefersAscii();
    }

    private function prefersAscii(): bool
    {
        return ConsoleApplicationHelper::prefersAsciiUi();
    }

    private function tableStyle(): string
    {
        return $this->prefersAscii() ? 'default' : 'box';
    }
}
