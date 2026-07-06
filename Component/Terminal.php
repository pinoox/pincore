<?php

/**
 *      ****  *  *     *  ****  ****  *    *
 *      *  *  *  * *   *  *  *  *  *   *  *
 *      ****  *  *  *  *  *  *  *  *    *
 *      *     *  *   * *  *  *  *  *   *  *
 *      *     *  *    **  ****  ****  *    *
 * @author   Pinoox
 * @link https://www.pinoox.com/
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Component;

use JetBrains\PhpStorm\NoReturn;
use Pinoox\Support\ProjectCli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

class Terminal extends Command
{
    protected InputInterface $input;
    protected OutputInterface $output;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        return Command::SUCCESS;
    }

    protected function info($message, $newLine = true)
    {
        $this->output->write($message);
        if ($newLine) $this->newline();
    }

    #[NoReturn] protected function error($message, $newLine = true): void
    {
        $this->output->write("<error>$message</error>");
        if ($newLine) {
            $this->output->writeln('');
        }

        throw new TerminalAbortException(is_string($message) ? $message : (string) $message);
    }

    protected function success($message, $newLine = true): void
    {
        $this->output->write("<info>$message</info>");
        if ($newLine) $this->newline();
    }

    protected function question($message, $newLine = true): void
    {
        $this->output->write("<question>$message</question>");
        if ($newLine) $this->newline();
    }

    protected function warning($message, $newLine = true): void
    {
        $this->output->write("<comment>$message</comment>");
        if ($newLine) $this->newline();
    }

    #[NoReturn] protected function newline(): void
    {
        $this->output->writeln('');
    }

    protected function stop(): void
    {
        throw new TerminalAbortException('Command stopped.');
    }

    protected function table($columns, $rows)
    {
        $table = new Table($this->output);
        $table->setHeaders($columns)
            ->setRows($rows);
        $table->render();
    }

    protected function confirm(string $message, InputInterface $input, OutputInterface $output): bool
    {
        $io = new SymfonyStyle($input, $output);

        $question = new ConfirmationQuestion($message, false);

        return $io->askQuestion($question);
    }

    protected function getDefaultPackage(): string
    {
        return _env('PINOOX_CLI_PACKAGE', \Pinoox\Support\Platform::PACKAGE);
    }

    protected function cliInvoke(): string
    {
        return ProjectCli::invoke();
    }

    protected function cliFormat(string $command): string
    {
        return ProjectCli::format($command);
    }

    protected function cliPinxFormat(string $command): string
    {
        return ProjectCli::pinxFormat($command);
    }

    protected function cliSuggest(string $scope, string $command): string
    {
        return ProjectCli::suggest($scope, $command);
    }

    /**
     * @param list<array{0: string, 1?: string}|string> $exampleEntries
     */
    protected function cliHelp(string $intro, array $exampleEntries, ?string $footer = null): string
    {
        return ProjectCli::helpBlock($intro, $exampleEntries, $footer);
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    protected function cliPinxProcessCommand(array $arguments): array
    {
        return ProjectCli::pinxProcessCommand($arguments);
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    protected function cliProcessCommand(array $arguments): array
    {
        return ProjectCli::processCommand($arguments);
    }
}