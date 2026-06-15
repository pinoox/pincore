<?php

namespace Pinoox\Terminal\Permission;

use Pinoox\Component\Terminal;
use Pinoox\Model\PermissionModel;
use Pinoox\Model\RoleModel;
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
    name: 'permission:create',
    description: 'Create a permission for an app or platform',
    aliases: ['make:permission'],
)]

class PermissionCreateCommand extends Terminal
{
    use SelectsPackage;
    use ManagesCliRoles;
    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Create a permission in the current access scope.
Examples:
  php pinoox permission:create
  php pinoox permission:create com_my_shop --key=manager.* --name="Manager panel"
  php pinoox permission:create com_my_shop --key=posts.edit --role=admin
Recommended workflow:
  1. php pinoox permission:create com_my_shop --key=manager.*
  2. php pinoox role:permission admin --permission=manager.*
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Unique permission_key (e.g. manager.* or posts.edit)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Short description')
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Attach to role_key (repeatable)');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveRolePackageInput($input, $output, $io, 'Create permission for');
        $this->prepareRoleScope($package);
        $permissionKey = trim((string) ($input->getOption('key') ?: ''));
        if ($permissionKey === '' && $input->isInteractive()) {
            $permissionKey = trim((string) $io->ask('Permission key (e.g. manager.* or posts.edit)'));
        }
        if ($permissionKey === '' || !$this->isValidPermissionKey($permissionKey)) {
            $io->error('Permission key is required (letters, numbers, _, -, ., * — must start with letter or digit).');
            return Command::FAILURE;
        }
        if (PermissionModel::query()->where('permission_key', $permissionKey)->exists()) {
            $io->error('Permission already exists: ' . $permissionKey);
            return Command::FAILURE;
        }
        $name = (string) ($input->getOption('name') ?: '');
        if ($name === '' && $input->isInteractive()) {
            $name = (string) $io->ask('Display name', $permissionKey);
        }
        $description = (string) ($input->getOption('description') ?: '');
        if ($description === '' && $input->isInteractive()) {
            $description = (string) $io->ask('Description', '');
        }
        try {
            $permission = PermissionModel::create([
                'permission_key' => $permissionKey,
                'name' => $name !== '' ? $name : null,
                'description' => $description !== '' ? $description : null,
            ]);
            /** @var list<string> $roleKeys */
            $roleKeys = $input->getOption('role');
            $roleKeys = array_values(array_filter(array_map('strval', $roleKeys)));
            foreach ($roleKeys as $roleKey) {
                $role = RoleModel::query()->where('role_key', $roleKey)->first();
                if ($role === null) {
                    throw new \InvalidArgumentException('Role not found: ' . $roleKey);
                }
                $this->assignPermissionsToRole($role, [$permissionKey]);
            }
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Failed to create permission: ' . $e->getMessage());
            return Command::FAILURE;
        }
        $io->success(sprintf(
            'Permission #%d created (%s) for app scope %s (context: %s).',
            $permission->permission_id,
            $permission->permission_key,
            $permission->app,
            $package,
        ));
        if ($roleKeys !== []) {
            $io->note('Attached to role(s): ' . implode(', ', $roleKeys));
        }
        return Command::SUCCESS;
    }
}
