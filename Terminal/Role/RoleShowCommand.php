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
    name: 'role:show',
    description: 'Show details for a role',
)]

class RoleShowCommand extends Terminal
{
    use SelectsPackage;
    use ManagesCliRoles;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Show a role by id or role_key.

Examples:
  php pinoox role:show admin
  php pinoox role:show com_my_shop editor
HELP
            )
            ->addArgument('role', InputArgument::OPTIONAL, 'Role id or role_key. Leave empty to pick from the list.')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);

        try {
            $package = $this->resolveRolePackageInput($input, $output, $io, 'Show role for');
            $this->prepareRoleScope($package);

            $role = $this->resolveRoleInput($input, $output, $io, 'Select role to show');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($role === null) {
            $io->error('Role not found.');

            return Command::FAILURE;
        }

        $permissions = $role->permissions()->pluck('permission_key')->all();
        $users = $role->users()->count();
        $row = $this->roleRow($role, withPermissions: true, withUsers: true);

        if ($input->getOption('json')) {
            $io->writeln(json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title('Role #' . $role->role_id);
        $io->definitionList(
            ['Package context' => $package],
            ['Key' => (string) $role->role_key],
            ['Name' => (string) ($role->name ?: '—')],
            ['Description' => (string) ($role->description ?: '—')],
            ['App scope' => (string) ($role->app ?: '—')],
            ['Permissions' => $permissions === [] ? '—' : implode(', ', $permissions)],
            ['Users assigned' => (string) $users],
            ['Created' => (string) ($role->created_at?->format('Y-m-d H:i:s') ?: '—')],
        );

        return Command::SUCCESS;
    }
}
