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
    name: 'user:status',
    description: 'Change a user status',
)]

class UserStatusCommand extends Terminal
{
    use ManagesCliUsers;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Set user status: active, inactive, suspend, pending.

Run without arguments for an interactive wizard. The user can be found by
user id, username, email, mobile, or personal id.

Examples:
  php pinoox user:status
  php pinoox user:status com_my_shop admin --status=inactive
  php pinoox user:status 09120000000 --status=active
  pinx user:status admin --status=active
HELP
            )
            ->addArgument('user', InputArgument::OPTIONAL, 'User id, username, email, mobile, or personal id')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'New status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);

        $useWizard = $input->isInteractive()
            && $this->resolveUserIdentifier($input) === ''
            && !$input->getOption('status');

        if ($useWizard) {
            $io->title('Change user status');
            $io->text('Find a user by id, username, email, mobile, or personal id, then set a new status.');
            $io->newLine();
        }

        $package = $this->resolveUserPackageInput($input, $output, $io, 'Change user status for');
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

        $status = (string) ($input->getOption('status') ?: '');
        if ($status === '') {
            $status = (string) $io->choice('Status', $this->userStatuses(), $user->status);
        }

        if (!in_array($status, $this->userStatuses(), true)) {
            $io->error('Invalid status. Use: ' . implode(', ', $this->userStatuses()));

            return Command::FAILURE;
        }

        if (!Auth::setStatus((int) $user->user_id, $status)) {
            $io->error('Failed to update status.');

            return Command::FAILURE;
        }

        $io->success(sprintf('User #%d (%s) status set to %s.', $user->user_id, $user->username, $status));

        return Command::SUCCESS;
    }
}
