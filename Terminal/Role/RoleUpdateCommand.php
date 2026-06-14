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
    name: 'role:update',
    description: 'Update a role name or description',
)]

class RoleUpdateCommand extends Terminal
{
    use SelectsPackage;
    use ManagesCliRoles;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Update role display fields.

Examples:
  php pinoox role:update admin --name=Super Admin
  php pinoox role:update --set name=Editor --set description="Content team"
HELP
            )
            ->addArgument('role', InputArgument::OPTIONAL, 'Role id or role_key. Leave empty to pick from the list.')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Set field=value (name, description)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Display name')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Description');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);

        try {
            $package = $this->resolveRolePackageInput($input, $output, $io, 'Update role for');
            $this->prepareRoleScope($package);

            $role = $this->resolveRoleInput($input, $output, $io, 'Select role to update');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($role === null) {
            $io->error('Role not found.');

            return Command::FAILURE;
        }

        $fields = $this->collectRoleUpdateFields($input);

        if ($fields === [] && $input->isInteractive()) {
            $fields = $this->promptRoleFieldUpdates($io, $role);
        }

        if ($fields === []) {
            $io->warning('Nothing to update. Pass --name, --description, or --set.');

            return Command::SUCCESS;
        }

        try {
            $role->fill($fields);
            $role->save();
        } catch (\Throwable $e) {
            $io->error('Failed to update role: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Role #' . $role->role_id . ' (' . $role->role_key . ') updated.');

        return Command::SUCCESS;
    }

    /**
     * @return array<string, string|null>
     */
    private function collectRoleUpdateFields(InputInterface $input): array
    {
        $fields = [];

        foreach ($input->getOption('set') ?? [] as $assignment) {
            if (!is_string($assignment) || !str_contains($assignment, '=')) {
                continue;
            }

            [$field, $value] = explode('=', $assignment, 2);
            $field = strtolower(trim($field));

            if (in_array($field, ['name', 'description'], true)) {
                $fields[$field] = $value;
            }
        }

        foreach (['name', 'description'] as $field) {
            $value = $input->getOption($field);
            if ($value !== null) {
                $fields[$field] = $value;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, string|null>
     */
    private function promptRoleFieldUpdates(SymfonyStyle $io, RoleModel $role): array
    {
        $fields = [];

        $io->section(sprintf('Update role #%d (%s)', $role->role_id, $role->role_key));
        $io->writeln('Press Enter to keep the current value.');

        foreach (['name', 'description'] as $field) {
            $current = (string) ($role->{$field} ?? '');
            $answer = $io->ask(ucfirst($field), $current);

            if (is_string($answer) && $answer !== $current) {
                $fields[$field] = $answer;
            }
        }

        return $fields;
    }
}
