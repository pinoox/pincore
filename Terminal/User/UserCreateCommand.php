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
    name: 'user:create',
    description: 'Create a user for an app or platform',
    aliases: ['make:user'],
)]

class UserCreateCommand extends Terminal
{
    use ManagesCliUsers;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Create a user scoped to an app package or platform.

Examples:
  php pinoox user:create com_my_shop --username=admin --password=secret --email=admin@example.com
  php pinoox user:create --username=demo --password=demo
  pinx user:create --username=admin --password=secret --role=admin
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp())
            ->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'Login username')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Plain password (hashed automatically)')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Email address')
            ->addOption('fname', null, InputOption::VALUE_REQUIRED, 'First name')
            ->addOption('lname', null, InputOption::VALUE_REQUIRED, 'Last name')
            ->addOption('mobile', null, InputOption::VALUE_REQUIRED, 'Mobile number')
            ->addOption('group-key', null, InputOption::VALUE_REQUIRED, 'Group key (e.g. admin)')
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Status: active, inactive, suspend, pending', UserModel::ACTIVE)
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED, 'Attach an existing role by role_key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveUserPackage($input, $output, $io, 'Create user for');
        $this->prepareUserScope($package);

        $username = (string) ($input->getOption('username') ?: '');
        $password = (string) ($input->getOption('password') ?: '');

        if ($username === '') {
            $username = (string) $io->ask('Username');
        }

        if ($password === '') {
            $question = new Question('Password');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password = (string) $io->askQuestion($question);
        }

        if (strlen($username) < 3) {
            $io->error('Username must be at least 3 characters.');

            return Command::FAILURE;
        }

        if (strlen($password) < 5) {
            $io->error('Password must be at least 5 characters.');

            return Command::FAILURE;
        }

        $status = (string) $input->getOption('status');
        if (!in_array($status, $this->userStatuses(), true)) {
            $io->error('Invalid status. Use: ' . implode(', ', $this->userStatuses()));

            return Command::FAILURE;
        }

        if (Auth::findByLogin($username, false) !== null) {
            $io->error('A user with this username already exists in package: ' . $package);

            return Command::FAILURE;
        }

        $email = (string) ($input->getOption('email') ?: '');
        if ($email !== '' && Auth::findByLogin($email, false) !== null) {
            $io->error('A user with this email already exists in package: ' . $package);

            return Command::FAILURE;
        }

        $data = [
            'username' => $username,
            'password' => $password,
            'status' => $status,
        ];

        foreach (['email', 'fname', 'lname', 'mobile'] as $field) {
            $value = $input->getOption($field);
            if (is_string($value) && $value !== '') {
                $data[$field] = $value;
            }
        }

        $groupKey = $input->getOption('group-key');
        if (is_string($groupKey) && $groupKey !== '') {
            $data['group_key'] = $groupKey;
        }

        try {
            $user = Auth::create($data);
        } catch (\Throwable $e) {
            $io->error('Failed to create user: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $roleKey = $input->getOption('role');
        if (is_string($roleKey) && $roleKey !== '') {
            if (!$this->attachRole($user, $roleKey)) {
                $io->warning('User created but role "' . $roleKey . '" was not found.');
            }
        }

        $io->success(sprintf(
            'User #%d created (%s) for app scope %s (context: %s).',
            $user->user_id,
            $user->username,
            $user->app,
            $package,
        ));

        return Command::SUCCESS;
    }
}
