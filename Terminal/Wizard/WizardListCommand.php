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

namespace Pinoox\Terminal\Wizard;

use Pinoox\Component\Kernel\Loader;
use Pinoox\Component\Terminal;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'wizard:list',
    description: 'Show list of .pinx packages (delegates to pinx:install)',
)]

class WizardListCommand extends Terminal
{

    const PATH = '/pins/';

    protected function configure(): void
    {
        $this->addOption('filter', 'f', InputOption::VALUE_NONE, 'Filter ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $files = $this->fetchPinxFiles();
        $selectedFiles = $this->selectFiles($files, $input, $output);

        if (count($selectedFiles) > 0) {
            $confirm = $this->confirm('Are you sure you want to install the selected files? (yes/no) ', $input, $output);

            if ($confirm) {
                $this->installFiles($selectedFiles);
            } else {
                $this->warning('Installation canceled.');
            }
        } else {
            $this->error('No .pinx files selected for installation.');
        }

        return Command::SUCCESS;
    }

    private function fetchPinxFiles(): array
    {
        $finder = new Finder();
        $path = Loader::getBasePath() . self::PATH;
        $finder->files()->in($path)->name('*.pinx');

        $files = [];
        foreach ($finder as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }

    private function selectFiles(array $files, InputInterface $input, OutputInterface $output): array
    {
        $io = new SymfonyStyle($input, $output);

        $choices = array_map(function ($file) {
            return pathinfo($file, PATHINFO_FILENAME);
        }, $files);

        $question = new ChoiceQuestion('Select [number].pinx file/files to install (comma-separated)', $choices);
        $question->setMultiselect(true);

        return $io->askQuestion($question);
    }

    private function installFiles(array $files): void
    {
        $this->success('Installing selected pinx packages...');

        foreach ($files as $app) {
            $process = new Process(['php', 'pinoox', 'pinx:install', $app]);
            $process->run();

            if ($process->isSuccessful()) {
                $this->success(sprintf('"%s" installed successfully.', $app));
            } else {
                $this->warning($process->getErrorOutput());
                $this->error(sprintf('Failed to install "%s"', $app));
            }
        }
    }
}
