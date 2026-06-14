<?php

namespace Pinoox\Terminal\Role;

use Pinoox\Component\Terminal;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Pinoox\Terminal\Role\Concerns\ManagesCliRoles;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'role:delete',
    description: 'Delete a role',
)]

class RoleDeleteCommand extends Terminal
{
    use SelectsPackage;
    use ManagesCliRoles;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Delete a role by id or role_key.

Examples:
  php pinoox role:delete admin
  php pinoox role:delete com_my_shop editor --force
HELP
            )
            ->addArgument('role', InputArgument::OPTIONAL, 'Role id or role_key. Leave empty to pick from the list.')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);

        try {
            $package = $this->resolveRolePackageInput($input, $output, $io, 'Delete role for');
            $this->prepareRoleScope($package);

            $role = $this->resolveRoleInput($input, $output, $io, 'Select role to delete');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($role === null) {
            $io->error('Role not found.');

            return Command::FAILURE;
        }

        $userCount = $role->users()->count();

        if ($userCount > 0) {
            $io->warning(sprintf('Role "%s" is assigned to %d user(s).', $role->role_key, $userCount));
        }

        if (!$input->getOption('force')) {
            if (!$input->isInteractive() || !$io->confirm(
                sprintf('Delete role #%d (%s)?', $role->role_id, $role->role_key),
                false,
            )) {
                $io->note('Aborted.');

                return Command::SUCCESS;
            }
        }

        try {
            $role->delete();
        } catch (\Throwable $e) {
            $io->error('Failed to delete role: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Role deleted: ' . $role->role_key);

        return Command::SUCCESS;
    }
}
