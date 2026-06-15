<?php

namespace Pinoox\Terminal\Permission;

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
    name: 'permission:delete',
    description: 'Delete a permission',
)]

class PermissionDeleteCommand extends Terminal
{
    use SelectsPackage;
    use ManagesCliRoles;
    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Delete a permission by id or permission_key.
Examples:
  php pinoox permission:delete posts.edit
  php pinoox permission:delete com_my_shop legacy --force
HELP
            )
            ->addArgument('permission', InputArgument::OPTIONAL, 'Permission id or permission_key. Leave empty to pick from the list.')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        try {
            $package = $this->resolveRolePackageInput($input, $output, $io, 'Delete permission for');
            $this->prepareRoleScope($package);
            $permission = $this->resolvePermissionInput($input, $output, $io, 'Select permission to delete');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        if ($permission === null) {
            $io->error('Permission not found.');
            return Command::FAILURE;
        }
        $roleCount = $permission->roles()->count();
        if ($roleCount > 0) {
            $roles = $permission->roles()->pluck('role_key')->all();
            $io->warning(sprintf(
                'Permission "%s" is used by %d role(s): %s',
                $permission->permission_key,
                $roleCount,
                implode(', ', $roles),
            ));
        }
        if (!$input->getOption('force')) {
            if (!$input->isInteractive() || !$io->confirm(
                sprintf('Delete permission #%d (%s)?', $permission->permission_id, $permission->permission_key),
                false,
            )) {
                $io->note('Aborted.');
                return Command::SUCCESS;
            }
        }
        try {
            $permission->delete();
        } catch (\Throwable $e) {
            $io->error('Failed to delete permission: ' . $e->getMessage());
            return Command::FAILURE;
        }
        $io->success('Permission deleted: ' . $permission->permission_key);
        return Command::SUCCESS;
    }
}
