<?php

namespace Pinoox\Terminal\Pinx;

use Pinoox\Component\Package\AppDependency;
use Pinoox\Component\Terminal;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\Pinx;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pinx:uninstall',
    description: 'Uninstall an app or theme with rollback, routes, and pinker cleanup',
    aliases: ['pinx:remove'],
)]

class PinxUninstallCommand extends Terminal
{
    use SelectsPackage;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Uninstall pipeline for apps installed via pinx or app:create:
validate, dependents, migrate rollback, routes, pinker, remove files.

Examples:
  php pinoox pinx:uninstall com_my_shop
  php pinoox pinx:uninstall com_my_shop --keep-files
  php pinoox pinx:uninstall com_my_shop --skip-migrate
  php pinoox pinx:uninstall com_my_shop --theme=spark
  php pinoox pinx:uninstall com_my_shop --force -y

For route-only removal without DB rollback, see: php pinoox app:delete --route-only
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, 'App package name (e.g. com_my_shop)')
            ->addOption('theme', 't', InputOption::VALUE_REQUIRED, 'Uninstall only a theme folder from the host app')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Uninstall even when other apps depend on this package')
            ->addOption('skip-migrate', null, InputOption::VALUE_NONE, 'Skip migration rollback')
            ->addOption('skip-routes', null, InputOption::VALUE_NONE, 'Skip URL route cleanup')
            ->addOption('keep-files', null, InputOption::VALUE_NONE, 'Keep app/theme files on disk (DB/routes/pinker cleanup only)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolvePackageRequired($input, $output, $io, [
            'appsOnly' => true,
            'sectionTitle' => 'Uninstall',
        ]);
        $theme = trim((string) ($input->getOption('theme') ?: ''));
        $isTheme = $theme !== '';

        if (!$isTheme && !AppEngine::exists($package)) {
            $io->error('App not found: ' . $package);

            return Command::FAILURE;
        }

        if ($isTheme && !AppEngine::exists($package)) {
            $io->error('Host app not found: ' . $package);

            return Command::FAILURE;
        }

        $io->section($isTheme ? 'Pinx theme uninstall' : 'Pinx app uninstall');
        $io->definitionList(
            ['Package' => $package],
            ['Target' => $isTheme ? 'theme/' . $theme : 'app'],
        );

        if (!$isTheme) {
            $dependents = AppDependency::dependents($package, AppEngine::___());

            if ($dependents !== []) {
                $io->warning('These apps depend on "' . $package . '": ' . implode(', ', $dependents));
            }

            $appFile = AppEngine::path($package, 'app.php');
            $config = is_file($appFile) ? include $appFile : [];

            if (is_array($config) && !empty($config['sys-app'])) {
                $io->caution('This is a system app (sys-app).');
            }
        }

        if (!$input->getOption('yes') && !$io->confirm('Proceed with uninstall?', false)) {
            $io->warning('Uninstall canceled.');

            return Command::SUCCESS;
        }

        $uninstaller = Pinx::uninstaller();
        $uninstaller->onStep(static function (string $step, string $status, string $message) use ($io): void {
            $io->writeln(sprintf('  <comment>[%s]</comment> %s: %s', strtoupper($status), $step, $message));
        });

        $options = [
            'force' => (bool) $input->getOption('force'),
            'skip_migrate' => (bool) $input->getOption('skip-migrate'),
            'skip_routes' => (bool) $input->getOption('skip-routes'),
            'keep_files' => (bool) $input->getOption('keep-files'),
        ];

        $result = $isTheme
            ? $uninstaller->uninstallTheme($package, $theme, $options)
            : $uninstaller->uninstallApp($package, $options);

        if (!$result->success) {
            $io->error($result->message);

            return Command::FAILURE;
        }

        $io->success($result->message);

        return Command::SUCCESS;
    }
}
