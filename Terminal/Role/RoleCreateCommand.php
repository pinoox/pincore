<?php

namespace Pinoox\Terminal\Role;

use Pinoox\Component\Terminal;
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
    name: 'role:create',
    description: 'Create a role for an app or platform',
    aliases: ['make:role'],
)]

class RoleCreateCommand extends Terminal
{
    use SelectsPackage;
    use ManagesCliRoles;
    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Create a role in the current access scope.
Examples:
  php pinoox role:create
  php pinoox role:create com_my_shop --key=admin --name=Administrator
  php pinoox role:create platform --key=editor --name=Editor --description="Content editor"
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Unique role_key (e.g. admin)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Short description')
            ->addOption('permission', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Attach permission_key (repeatable)');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveRolePackageInput($input, $output, $io, 'Create role for');
        $this->prepareRoleScope($package);
        $roleKey = trim((string) ($input->getOption('key') ?: ''));
        if ($roleKey === '' && $input->isInteractive()) {
            $roleKey = trim((string) $io->ask('Role key (e.g. admin)'));
        }
        if ($roleKey === '' || !preg_match('/^[a-z][a-z0-9_\-]*$/i', $roleKey)) {
            $io->error('Role key is required and must start with a letter (letters, numbers, _, -).');
            return Command::FAILURE;
        }
        if (RoleModel::query()->where('role_key', $roleKey)->exists()) {
            $io->error('Role already exists: ' . $roleKey);
            return Command::FAILURE;
        }
        $name = (string) ($input->getOption('name') ?: '');
        if ($name === '' && $input->isInteractive()) {
            $name = (string) $io->ask('Display name', ucfirst($roleKey));
        }
        $description = (string) ($input->getOption('description') ?: '');
        if ($description === '' && $input->isInteractive()) {
            $description = (string) $io->ask('Description', '');
        }
        try {
            $role = RoleModel::create([
                'role_key' => $roleKey,
                'name' => $name !== '' ? $name : null,
                'description' => $description !== '' ? $description : null,
            ]);
            /** @var list<string> $permissions */
            $permissions = $input->getOption('permission');
            $permissions = array_values(array_filter(array_map('strval', $permissions)));
            if ($permissions !== []) {
                $this->assignPermissionsToRole($role, $permissions);
            }
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Failed to create role: ' . $e->getMessage());
            return Command::FAILURE;
        }
        $io->success(sprintf(
            'Role #%d created (%s) for app scope %s (context: %s).',
            $role->role_id,
            $role->role_key,
            $role->app,
            $package,
        ));
        return Command::SUCCESS;
    }
}
