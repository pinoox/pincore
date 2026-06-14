<?php

namespace Pinoox\Terminal\User;

use Pinoox\Component\Terminal;
use Pinoox\Portal\Auth;
use Pinoox\Terminal\User\Concerns\ManagesCliUsers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'user:delete',
    description: 'Delete a user',
)]

class UserDeleteCommand extends Terminal
{
    use ManagesCliUsers;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Delete a user from the current app scope.

Examples:
  php pinoox user:delete com_my_shop demo --force
  pinx user:delete demo
HELP
            )
            ->addArgument('user', InputArgument::REQUIRED, 'User id, username, or email')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation')
            ->addOption('revoke-sessions', null, InputOption::VALUE_NONE, 'Revoke tokens before delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveUserPackageInput($input, $output, $io, 'Delete user for');
        $this->prepareUserScope($package);

        $identifier = $this->resolveUserIdentifier($input);
        $user = $this->resolveUser($identifier);

        if ($user === null) {
            $io->error('User not found: ' . $identifier);

            return Command::FAILURE;
        }

        if (!$input->getOption('force') && !$io->confirm(
            sprintf('Delete user #%d (%s)?', $user->user_id, $user->username),
            false,
        )) {
            $io->warning('Delete canceled.');

            return Command::SUCCESS;
        }

        if ($input->getOption('revoke-sessions')) {
            Auth::revokeSessions((int) $user->user_id);
        }

        if (!Auth::remove((int) $user->user_id)) {
            $io->error('Failed to delete user.');

            return Command::FAILURE;
        }

        $io->success('User #' . $user->user_id . ' deleted.');

        return Command::SUCCESS;
    }
}
