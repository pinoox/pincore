<?php

namespace Pinoox\Terminal\Doctor;

use Pinoox\Component\Doctor\DoctorMeta;
use Pinoox\Component\Doctor\DoctorPresenter;
use Pinoox\Component\Doctor\DoctorProject;
use Pinoox\Component\Doctor\DoctorRunner;
use Pinoox\Component\Package\AppManifest;
use Pinoox\Component\Terminal;
use Pinoox\Support\ProjectCli;
use Pinoox\Support\SystemConfig;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'doctor',
    description: 'Deep health check: PHP, platform layout, env, database, frontend, and build readiness',
)]
class DoctorCommand extends Terminal
{
    use SelectsPackage;

    protected function configure(): void
    {
        $this
            ->setHelp(
                ProjectCli::helpBlock(
                    'Runs structured diagnostics for the platform or a single HMVC app.',
                    [
                        'doctor',
                        'doctor com_pinoox_manager',
                        'doctor --json com_pinoox_manager',
                        'doctor --skip-db platform',
                    ],
                    'Use platform scope for project-wide checks only.',
                )
            )
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp())
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON report')
            ->addOption('skip-db', null, InputOption::VALUE_NONE, 'Skip database connectivity and migration checks')
            ->addOption('skip-frontend', null, InputOption::VALUE_NONE, 'Skip Node/npm and frontend checks')
            ->addOption('no-fixes', null, InputOption::VALUE_NONE, 'Hide suggested fix commands');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolvePackageRequired($input, $output, $io);
        $project = DoctorProject::resolve(SystemConfig::rootPath(), $package);
        $runner = new DoctorRunner(
            skipDatabase: (bool) $input->getOption('skip-db'),
            skipFrontend: (bool) $input->getOption('skip-frontend'),
        );
        $report = $runner->runProject(SystemConfig::rootPath(), $package);

        if ($input->getOption('json')) {
            $output->writeln(json_encode(
                $report->toArray(DoctorMeta::forProject($project)),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));

            return $report->isHealthy() ? Command::SUCCESS : Command::FAILURE;
        }

        $scopeLabel = $project->package === 'platform'
            ? 'Platform'
            : AppManifest::displayName($project->package) . ' (' . $project->package . ')';

        (new DoctorPresenter())->render(
            $io,
            $report,
            $scopeLabel,
            $project->root,
            !(bool) $input->getOption('no-fixes'),
        );

        return $report->isHealthy() ? Command::SUCCESS : Command::FAILURE;
    }
}
