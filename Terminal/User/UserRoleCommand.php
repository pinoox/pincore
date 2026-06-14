<?php

namespace Pinoox\Terminal\User;

use Pinoox\Component\Terminal;
use Pinoox\Model\RoleModel;
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
    description: 'Assign or sync roles for a user',
    aliases: ['user:role:assign'],
)]

class UserRoleCommand extends Terminal
{
    use ManagesCliUsers;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Assign roles to a user by role_key.

Examples:
  php pinoox user:role com_my_shop admin --role=admin
  pinx user:role admin --role=editor --sync
HELP
            )
            ->addArgument('user', InputArgument::REQUIRED, 'User id, username, or email')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Role key (repeatable)')
            ->addOption('sync', null, InputOption::VALUE_NONE, 'Replace existing roles instead of attaching');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveUserPackageInput($input, $output, $io, 'Assign roles for');
        $this->prepareUserScope($package);

        $identifier = $this->resolveUserIdentifier($input);
        $user = $this->resolveUser($identifier);

        if ($user === null) {
            $io->error('User not found: ' . $identifier);

            return Command::FAILURE;
        }

        /** @var list<string> $roleKeys */
        $roleKeys = $input->getOption('role');
        $roleKeys = array_values(array_filter(array_map('strval', $roleKeys)));

        if ($roleKeys === []) {
            $roleKeys = [(string) $io->ask('Role key')];
        }

        $roleIds = RoleModel::query()
            ->whereIn('role_key', $roleKeys)
            ->pluck('role_id', 'role_key')
            ->all();

        $missing = array_values(array_diff($roleKeys, array_keys($roleIds)));
        if ($missing !== []) {
            $io->error('Role(s) not found: ' . implode(', ', $missing));

            return Command::FAILURE;
        }

        $ids = array_values($roleIds);

        if ($input->getOption('sync')) {
            $user->roles()->sync($ids);
        } else {
            $user->roles()->syncWithoutDetaching($ids);
        }

        $io->success(sprintf(
            'Roles [%s] %s user #%d.',
            implode(', ', $roleKeys),
            $input->getOption('sync') ? 'synced to' : 'attached to',
            $user->user_id,
        ));

        return Command::SUCCESS;
    }
}
