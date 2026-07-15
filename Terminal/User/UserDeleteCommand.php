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

Run without arguments for an interactive wizard. The user can be found by
user id, username, email, mobile, or personal id.

Examples:
  php pinoox user:delete
  php pinoox user:delete com_my_shop demo --force
  php pinoox user:delete 09120000000 --force
  pinx user:delete demo --revoke-sessions
HELP
            )
            ->addArgument('user', InputArgument::OPTIONAL, 'User id, username, email, mobile, or personal id')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation')
            ->addOption('revoke-sessions', null, InputOption::VALUE_NONE, 'Revoke tokens before delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);

        $useWizard = $input->isInteractive() && $this->resolveUserIdentifier($input) === '';

        if ($useWizard) {
            $io->title('Delete user');
            $io->text('Find a user by id, username, email, mobile, or personal id.');
            $io->newLine();
        }

        $package = $this->resolveUserPackageInput($input, $output, $io, 'Delete user for');
        $this->prepareUserScope($package);

        try {
            $user = $this->resolveCliUserFromInput(
                $input,
                $output,
                $io,
                'Multiple users found — which one?',
            );
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($user === null) {
            $io->error('User not found.');

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

        $io->success('User #' . $user->user_id . ' (' . $user->username . ') deleted.');

        return Command::SUCCESS;
    }
}
