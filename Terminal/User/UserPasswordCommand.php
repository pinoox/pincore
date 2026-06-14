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

Examples:
  php pinoox user:password com_my_shop admin --password=secret
  pinx user:password admin --password=secret --revoke-sessions
HELP
            )
            ->addArgument('user', InputArgument::REQUIRED, 'User id, username, or email')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'New plain password')
            ->addOption('revoke-sessions', null, InputOption::VALUE_NONE, 'Revoke active tokens after reset');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveUserPackageInput($input, $output, $io, 'Reset password for');
        $this->prepareUserScope($package);

        $identifier = $this->resolveUserIdentifier($input);
        $user = $this->resolveUser($identifier);

        if ($user === null) {
            $io->error('User not found: ' . $identifier);

            return Command::FAILURE;
        }

        $password = (string) ($input->getOption('password') ?: '');
        if ($password === '') {
            $question = new Question('New password');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password = (string) $io->askQuestion($question);
        }

        if (strlen($password) < 5) {
            $io->error('Password must be at least 5 characters.');

            return Command::FAILURE;
        }

        UserModel::updatePassword((int) $user->user_id, $password);

        if ($input->getOption('revoke-sessions')) {
            $count = Auth::revokeSessions((int) $user->user_id);
            $io->note('Revoked ' . $count . ' session token(s).');
        }

        $io->success('Password updated for user #' . $user->user_id . '.');

        return Command::SUCCESS;
    }
}
