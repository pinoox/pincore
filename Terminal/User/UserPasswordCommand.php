<?php

namespace Pinoox\Terminal\User;

use Pinoox\Component\Terminal;
use Pinoox\Model\UserModel;
use Pinoox\Portal\Auth;
use Pinoox\Terminal\User\Concerns\ManagesCliUsers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'user:password',
    description: 'Reset a user password (admin)',
    aliases: ['user:passwd'],
)]

class UserPasswordCommand extends Terminal
{
    use ManagesCliUsers;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Reset a user password without the old password (admin CLI).

Run without arguments for an interactive wizard. The user can be found by
user id, username, email, mobile, or personal id. If username, email, or
mobile matches more than one user, you will be asked to pick the user id.

Examples:
  php pinoox user:password
  php pinoox user:password com_my_shop admin --password=secret
  php pinoox user:password 09120000000
  pinx user:password admin@example.com --password=secret --revoke-sessions
HELP
            )
            ->addArgument('user', InputArgument::OPTIONAL, 'User id, username, email, mobile, or personal id')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'New plain password')
            ->addOption('revoke-sessions', null, InputOption::VALUE_NONE, 'Revoke active tokens after reset')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);

        $useWizard = $input->isInteractive()
            && $this->resolveUserIdentifier($input) === ''
            && !$input->getOption('password');

        if ($useWizard) {
            $io->title('Reset user password');
            $io->text('Find a user by id, username, email, mobile, or personal id, then set a new password.');
            $io->newLine();
        }

        $package = $this->resolveUserPackageInput($input, $output, $io, 'Reset password for');
        $this->prepareUserScope($package);

        try {
            $user = $this->resolveCliUserFromInput(
                $input,
                $output,
                $io,
                'Multiple users found — which one?',
            );
        } catch (\RuntimeException $e) {
            return $this->failUserJson($io, $input, $e->getMessage());
        }

        if ($user === null) {
            return $this->failUserJson($io, $input, 'User not found.');
        }

        $password = (string) ($input->getOption('password') ?: '');
        if ($password === '') {
            $question = new Question('New password');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password = (string) $io->askQuestion($question);
        }

        if (strlen($password) < 5) {
            return $this->failUserJson($io, $input, 'Password must be at least 5 characters.');
        }

        UserModel::updatePassword((int) $user->user_id, $password);

        $revoked = 0;
        if ($input->getOption('revoke-sessions')) {
            $revoked = Auth::revokeSessions((int) $user->user_id);
            if (!$input->getOption('json')) {
                $io->note('Revoked ' . $revoked . ' session token(s).');
            }
        }

        $message = 'Password updated for user #' . $user->user_id . ' (' . $user->username . ').';
        if ($input->getOption('json')) {
            $this->writeUserJson($io, [
                'ok' => true,
                'message' => $message,
                'user_id' => $user->user_id,
                'username' => $user->username,
                'revoked_sessions' => $revoked,
            ]);

            return Command::SUCCESS;
        }

        $io->success($message);

        return Command::SUCCESS;
    }
}
