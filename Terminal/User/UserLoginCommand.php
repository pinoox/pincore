<?php

namespace Pinoox\Terminal\User;

use Pinoox\Component\Terminal;
use Pinoox\Component\User\AuthConfig;
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
    name: 'user:login',
    description: 'Authenticate a user and print a session/JWT token',
)]
class UserLoginCommand extends Terminal
{
    use ManagesCliUsers;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Authenticate against the user scope resolved from the app's transport.user settings.

Examples:
  php pinoox user:login com_my_shop --username=admin --password=secret
  php pinoox user:login platform --id=1 --remember
  php pinoox user:login platform -u admin --remember
  pinx user:login --username=admin --password=secret --json
  pinx user:login --id=1 --json
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp())
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Login by user id (skips password)')
            ->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'Username or email')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Plain password')
            ->addOption('remember', 'r', InputOption::VALUE_NONE, 'Use remember-me lifetime')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveUserPackage($input, $output, $io, 'Login user for');
        $this->prepareUserScope($package);
        $this->prepareCliRequestContext();

        $remember = (bool) $input->getOption('remember');
        $userIdOption = $input->getOption('id');

        try {
            if ($userIdOption !== null && $userIdOption !== '') {
                [$user, $token] = $this->loginById($io, $userIdOption, $remember);
            } else {
                [$user, $token] = $this->loginByCredentials($input, $io, $remember);
            }
        } catch (\Throwable $e) {
            $io->error('Login failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($user === null) {
            return Command::FAILURE;
        }

        $auth = AuthConfig::resolve();
        $payload = [
            'user_id' => $user->user_id,
            'username' => $user->username,
            'email' => $user->email,
            'app' => $user->app,
            'context' => $package,
            'token' => $token,
            'remember' => $remember,
            'auth_key' => (string) ($auth['key'] ?? ''),
            'auth_mode' => (string) ($auth['mode'] ?? AuthConfig::MODE_COOKIE),
        ];

        if ($input->getOption('json')) {
            $io->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Logged in as #%s (%s) for app scope %s (context: %s).',
            (string) $user->user_id,
            (string) $user->username,
            (string) ($user->app ?? '—'),
            $package,
        ));

        $io->definitionList(
            ['User ID' => (string) $user->user_id],
            ['Username' => (string) $user->username],
            ['Email' => (string) ($user->email ?: '—')],
            ['App scope' => (string) ($user->app ?? '—')],
            ['Context' => $package],
            ['Auth mode' => (string) ($auth['mode'] ?? '—')],
            ['Auth key' => (string) ($auth['key'] ?? '—')],
            ['Token' => $token !== '' ? $token : '—'],
        );

        return Command::SUCCESS;
    }

    /**
     * @return array{0: ?UserModel, 1: string}
     */
    private function loginById(SymfonyStyle $io, mixed $userIdOption, bool $remember): array
    {
        if (!is_numeric($userIdOption) || (int) $userIdOption <= 0) {
            $io->error('User id must be a positive integer.');

            return [null, ''];
        }

        $userId = (int) $userIdOption;
        $user = Auth::find($userId);

        if ($user === null) {
            $io->error('User not found: #' . $userId);

            return [null, ''];
        }

        if ($user->status !== UserModel::ACTIVE) {
            $io->error(sprintf('User #%d is not active (status: %s).', $userId, $user->status));

            return [null, ''];
        }

        $token = (string) (Auth::login($user, $remember) ?? '');

        return [$user, $token];
    }

    /**
     * @return array{0: ?UserModel, 1: string}
     */
    private function loginByCredentials(InputInterface $input, SymfonyStyle $io, bool $remember): array
    {
        $username = (string) ($input->getOption('username') ?: '');
        $password = (string) ($input->getOption('password') ?: '');

        if ($username === '') {
            if (!$input->isInteractive()) {
                $io->error('Provide --id or --username in non-interactive mode.');

                return [null, ''];
            }
            $username = (string) $io->ask('Username or email');
        }

        if ($password === '') {
            if (!$input->isInteractive()) {
                $io->error('Password is required in non-interactive mode.');

                return [null, ''];
            }
            $question = new Question('Password');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password = (string) $io->askQuestion($question);
        }

        if ($username === '' || $password === '') {
            $io->error('Username and password are required.');

            return [null, ''];
        }

        $result = Auth::attemptResult([
            'username' => $username,
            'password' => $password,
        ], $remember);

        if (!$result->success) {
            $message = $result->message ?: ($result->reason ?: 'Login failed.');
            $io->error($message);

            return [null, ''];
        }

        return [$result->user, (string) ($result->token ?? '')];
    }

    protected function prepareCliRequestContext(): void
    {
        $_SERVER['REMOTE_ADDR'] = (string) ($_SERVER['REMOTE_ADDR'] ?? '') !== ''
            ? (string) $_SERVER['REMOTE_ADDR']
            : '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '') !== ''
            ? (string) $_SERVER['HTTP_USER_AGENT']
            : 'pinoox-cli';
    }
}
