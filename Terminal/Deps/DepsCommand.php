<?php

namespace Pinoox\Terminal\Deps;

use Pinoox\Component\Deps\DependencyInstallOptions;
use Pinoox\Component\Deps\DependencyInstaller;
use Pinoox\Component\Deps\DependencyRunResult;
use Pinoox\Component\Deps\DependencyScanner;
use Pinoox\Component\Deps\DependencyTarget;
use Pinoox\Component\Template\Frontend\ThemeFrontendDevTarget;
use Pinoox\Component\Terminal;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'deps',
    description: 'Install, update, and inspect Composer and npm dependencies across the project',
    aliases: ['dep'],
)]

class DepsCommand extends Terminal
{
    use SelectsPackage;

    private DependencyScanner $scanner;

    private DependencyInstaller $installer;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Manage Composer (PHP) and npm (theme frontend) dependencies for the whole project or a single app.

Actions:
  status    List discovered manifests and whether vendor/node_modules exist
  install   Run composer install / npm install (or npm ci when lockfile exists)
  update    Run composer update / npm update

Scopes:
  all         Project root + every app composer.json and theme package.json
  platform    Project root composer.json only
  com_my_shop Single app composer.json + active theme package.json

Examples:
  php pinoox deps
  php pinoox deps status all
  php pinoox deps install platform
  php pinoox deps install com_pinoox_manager
  php pinoox deps install com_pinoox_manager --all-themes
  php pinoox deps install com_pinoox_manager --theme=panel
  php pinoox deps install com_pinoox_manager --theme=all
  php pinoox deps install all --production
  php pinoox deps update com_my_shop --composer-only

Leave action and scope empty to pick interactively.
HELP
            )
            ->addArgument('action', InputArgument::OPTIONAL, 'Action: status, install, update (interactive when omitted)')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(allowAll: true))
            ->addOption('composer-only', null, InputOption::VALUE_NONE, 'Only run Composer targets')
            ->addOption('npm-only', null, InputOption::VALUE_NONE, 'Only run npm targets')
            ->addOption('theme', null, InputOption::VALUE_REQUIRED, 'Theme folder, theme context (site, panel, …), or all')
            ->addOption('all-themes', null, InputOption::VALUE_NONE, 'Include every theme context or theme folder with package.json')
            ->addOption('production', null, InputOption::VALUE_NONE, 'Composer: install/update without dev dependencies')
            ->addOption('no-ci', null, InputOption::VALUE_NONE, 'npm: use install instead of ci when package-lock.json exists')
            ->addOption('plain', null, InputOption::VALUE_NONE, 'Plain output without step panels (CI-friendly)')
            ->addOption('continue-on-error', null, InputOption::VALUE_NONE, 'Continue remaining targets when one step fails');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $presenter = new DepsConsolePresenter($io, $output, (bool) $input->getOption('plain'));
        $action = strtolower(trim((string) $input->getArgument('action')));

        if ($action === '') {
            try {
                $action = $this->resolveDepsAction($input, $output, $io);
            } catch (\Throwable $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        if (!in_array($action, ['status', 'install', 'update'], true)) {
            $io->error('Unknown action "' . $action . '". Use status, install, or update.');

            return Command::FAILURE;
        }

        if ((bool) $input->getOption('composer-only') && (bool) $input->getOption('npm-only')) {
            $io->error('Use only one of --composer-only or --npm-only.');

            return Command::FAILURE;
        }

        $this->scanner = new DependencyScanner();
        $this->installer = new DependencyInstaller();

        $package = $this->resolvePackageRequired($input, $output, $io, [
            'allowAll' => true,
            'default' => 'all',
            'sectionTitle' => 'Dependency scope',
        ]);

        [$themeName, $allThemes] = $this->resolveThemeSelection($input);

        $typeFilter = $this->resolveTypeFilter($input);
        $targets = $this->scanner->discover(
            scope: $package,
            themeName: $themeName,
            allThemes: $allThemes,
            typeFilter: $typeFilter,
        );

        if ($targets === []) {
            $io->warning('No composer.json or package.json targets were found for scope: ' . $package);

            return Command::SUCCESS;
        }

        return match ($action) {
            'status' => $this->runStatus($presenter, $package, $targets),
            'install' => $this->runInstall($presenter, $package, $targets, $input),
            'update' => $this->runUpdate($presenter, $package, $targets, $input),
        };
    }

    /**
     * @param list<DependencyTarget> $targets
     */
    private function runStatus(DepsConsolePresenter $presenter, string $scope, array $targets): int
    {
        $presenter->renderHeader('status', $scope, $targets);
        $presenter->renderStatusBoard($targets);

        return Command::SUCCESS;
    }

    /**
     * @param list<DependencyTarget> $targets
     */
    private function runInstall(DepsConsolePresenter $presenter, string $scope, array $targets, InputInterface $input): int
    {
        return $this->runDependencyAction($presenter, $scope, $targets, $input, 'install');
    }

    /**
     * @param list<DependencyTarget> $targets
     */
    private function runUpdate(DepsConsolePresenter $presenter, string $scope, array $targets, InputInterface $input): int
    {
        return $this->runDependencyAction($presenter, $scope, $targets, $input, 'update');
    }

    /**
     * @param list<DependencyTarget> $targets
     */
    private function runDependencyAction(
        DepsConsolePresenter $presenter,
        string $scope,
        array $targets,
        InputInterface $input,
        string $action,
    ): int {
        $options = new DependencyInstallOptions(
            production: (bool) $input->getOption('production'),
            npmCi: !(bool) $input->getOption('no-ci'),
        );

        $presenter->renderHeader($action, $scope, $targets);
        $presenter->renderPlan($action, $targets);

        $results = $presenter->runWorkflow(
            $action,
            $targets,
            function (DependencyTarget $target, callable $onOutput) use ($action, $options): DependencyRunResult {
                return $action === 'install'
                    ? $this->installer->install($target, $options, $onOutput)
                    : $this->installer->update($target, $options, $onOutput);
            },
            continueOnError: (bool) $input->getOption('continue-on-error'),
        );

        $exitCode = $presenter->renderFinalSummary($action, $results);

        return $exitCode === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    private function resolveTypeFilter(InputInterface $input): ?string
    {
        if ((bool) $input->getOption('composer-only')) {
            return 'composer';
        }

        if ((bool) $input->getOption('npm-only')) {
            return 'npm';
        }

        return null;
    }

    private function resolveDepsAction(InputInterface $input, OutputInterface $output, SymfonyStyle $io): string
    {
        if (!$input->isInteractive()) {
            throw new \RuntimeException('Action is required in non-interactive mode. Use status, install, or update.');
        }

        $choices = [
            'status' => 'Show dependency inventory',
            'install' => 'Install Composer and npm dependencies',
            'update' => 'Update Composer and npm dependencies',
        ];

        $io->section('Dependency action');
        $io->table(['Action', 'Description'], array_map(
            static fn (string $action, string $description): array => [$action, $description],
            array_keys($choices),
            array_values($choices),
        ));

        $question = new Question('Select action [status]: ', 'status');
        $question->setAutocompleterValues(array_keys($choices));
        $question->setValidator(static function ($answer) use ($choices): string {
            $answer = strtolower(trim((string) $answer));

            if (!isset($choices[$answer])) {
                throw new \RuntimeException('Choose status, install, or update.');
            }

            return $answer;
        });

        return $this->getHelper('question')->ask($input, $output, $question);
    }

    /**
     * @return array{0: ?string, 1: bool}
     */
    private function resolveThemeSelection(InputInterface $input): array
    {
        $themeOption = trim((string) ($input->getOption('theme') ?? ''));
        $allThemes = (bool) $input->getOption('all-themes');

        if ($allThemes || ThemeFrontendDevTarget::isAllContexts($themeOption)) {
            return [null, true];
        }

        if ($themeOption !== '') {
            return [$themeOption, false];
        }

        return [null, false];
    }
}
