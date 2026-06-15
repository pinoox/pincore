<?php

namespace Pinoox\Terminal\User;

use Pinoox\Component\Terminal;
use Pinoox\Terminal\Role\Concerns\ManagesCliRoles;
use Pinoox\Terminal\User\Concerns\ManagesCliUsers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
#[AsCommand(
    name: 'user:role',
    description: 'Assign, sync, or remove roles for a user',
    aliases: ['user:role:assign'],
)]

class UserRoleCommand extends Terminal
{
    use ManagesCliUsers;
    use ManagesCliRoles;
    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Manage roles assigned to a user.
Recommended workflow:
  1. php pinoox role:create com_my_shop --key=admin --name=Administrator
  2. php pinoox role:list com_my_shop
  3. php pinoox user:role admin com_my_shop --role=admin
Examples:
  php pinoox user:role
  php pinoox user:role admin --role=admin --role=editor
  php pinoox user:role admin --role=editor --sync
  php pinoox user:role admin --role=legacy --detach
  php pinoox user:role admin --list
HELP
            )
            ->addArgument('user', InputArgument::OPTIONAL, 'User id, username, or email. Leave empty to pick from the list.')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Role key (repeatable)')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Replace existing roles instead of attaching')
            ->addOption('detach', null, InputOption::VALUE_NONE, 'Remove role(s) instead of attaching')
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List current roles and exit');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);
        try {
            $package = $this->resolveUserPackageInput($input, $output, $io, 'Manage user roles for');
            $this->prepareRoleScope($package);
            $user = $this->resolveUserInput($input, $output, $io, 'Select user');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        if ($user === null) {
            $io->error('User not found.');
            return Command::FAILURE;
        }
        $currentRoles = $user->roles()->pluck('role_key')->all();
        if ($input->getOption('list')) {
            $io->title('Roles for user #' . $user->user_id . ' (' . $user->username . ')');
            $io->listing($currentRoles === [] ? ['— none —'] : $currentRoles);
            return Command::SUCCESS;
        }
        /** @var list<string> $roleKeys */
        $roleKeys = $input->getOption('role');
        $roleKeys = array_values(array_filter(array_map('strval', $roleKeys)));
        if ($roleKeys === [] && $input->isInteractive()) {
            $roleKeys = $this->promptRoleKeys($io, $user, allowMultiple: !$input->getOption('detach'));
        }
        if ($roleKeys === []) {
            $io->warning('Nothing to do. Pass --role=, --list, or run interactively.');
            $io->note('Current roles: ' . ($currentRoles === [] ? '—' : implode(', ', $currentRoles)));
            return Command::SUCCESS;
        }
        try {
            if ($input->getOption('detach')) {
                $this->detachRolesFromUser($user, $roleKeys);
                $action = 'detached from';
            } elseif ($input->getOption('sync')) {
                $this->assignRolesToUser($user, $roleKeys, sync: true);
                $action = 'synced to';
            } else {
                $this->assignRolesToUser($user, $roleKeys);
                $action = 'attached to';
            }
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        $user->load('roles');
        $updated = $user->roles()->pluck('role_key')->all();
        $io->success(sprintf(
            'Roles [%s] %s user #%d.',
            implode(', ', $roleKeys),
            $action,
            $user->user_id,
        ));
        $io->writeln('Current roles: ' . ($updated === [] ? '—' : implode(', ', $updated)));
        return Command::SUCCESS;
    }
}
