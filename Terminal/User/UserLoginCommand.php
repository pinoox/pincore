<?php

namespace Pinoox\Terminal\User;

use Pinoox\Component\Terminal;
use Pinoox\Component\User\AuthConfig;
use Pinoox\Component\User\DevLogin;
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
    description: 'Login a user, print token for browser apply, and set PINOOX_LOGIN',
)]
class UserLoginCommand extends Terminal
{
    use ManagesCliUsers;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Authenticate a user for an app scope.

Writes PINOOX_LOGIN=package:id:N to .env (server auto-login) and prints a
token for Inspector “Apply to browser” (localStorage/cookie).

Clear with: user:logout

Examples:
  php pinoox user:login
  php pinoox user:login com_my_shop --id=1
  php pinoox user:login platform --id=1 --no-env
  pinx user:login --id=1

.env:
  PINOOX_LOGIN=com_pinoox_manager:id:1
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp())
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Login by user id (skips password)')
            ->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'Username or email')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Plain password')
            ->addOption('remember', 'r', InputOption::VALUE_NONE, 'Use remember-me lifetime')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Persist PINOOX_LOGIN (default; kept for compatibility)')
            ->addOption('no-env', null, InputOption::VALUE_NONE, 'Do not write PINOOX_LOGIN to .env')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Deprecated: use user:logout')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('clear')) {
            $io->warning('user:login --clear is deprecated; use user:logout instead.');

            return $this->clearDevLogin($io, (bool) $input->getOption('json'));
        }

        $useWizard = $input->isInteractive()
            && !$input->getOption('json')
            && !$this->hasExplicitCredentials($input);

        if ($useWizard) {
            $io->title('User login');
            $io->text('Signs in, sets PINOOX_LOGIN in .env, and prints a browser token.');
            $io->newLine();
        }

        $package = $this->resolveUserPackage($input, $output, $io, $useWizard ? 'Which app?' : 'Login user for');
        $this->prepareUserScope($package);
        $this->prepareCliRequestContext();

        $remember = (bool) $input->getOption('remember');

        try {
            if ($useWizard) {
                [$user, $token] = $this->runLoginWizard($input, $output, $io, $package, $remember);
                $persist = $this->shouldPersistEnv($input);
            } else {
                $userIdOption = $input->getOption('id');
                if ($userIdOption !== null && $userIdOption !== '') {
                    [$user, $token] = $this->loginById($io, $userIdOption, $remember);
                } else {
                    [$user, $token] = $this->loginByCredentials($input, $io, $remember);
                }
                $persist = $this->shouldPersistEnv($input);
            }
        } catch (\Throwable $e) {
            $io->error('Login failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($user === null) {
            return Command::FAILURE;
        }

        $auth = AuthConfig::resolve();
        $persisted = false;

        if ($persist) {
            $persisted = DevLogin::remember([
                'package' => $package,
                'field' => 'id',
                'value' => (string) $user->user_id,
                'user_id' => $user->user_id,
                'username' => $user->username,
            ]);
        }

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
            'dev_login' => $persisted,
            'dev_login_enabled' => DevLogin::enabled(),
            'pinoox_login' => DevLogin::expression($package),
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
            ['.env' => $persisted ? 'PINOOX_LOGIN=' . DevLogin::expression($package) : 'not updated'],
        );

        if ($persisted) {
            $io->note('Auto-login is active while PINOOX_LOGIN is set. Clear with: user:logout');
        }

        return Command::SUCCESS;
    }

    private function hasExplicitCredentials(InputInterface $input): bool
    {
        $id = (string) ($input->getOption('id') ?? '');
        $username = (string) ($input->getOption('username') ?? '');

        return $id !== '' || $username !== '';
    }

    /**
     * @return array{0: ?UserModel, 1: string}
     */
    private function runLoginWizard(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $package,
        bool $remember,
    ): array {
        $io->section('User');
        $io->text(sprintf('App context: <info>%s</info>', $package));

        $method = $io->choice(
            'Sign in with',
            ['id' => 'User id', 'login' => 'Username or email', 'pick' => 'Pick from user list'],
            'pick',
        );

        $user = null;
        $token = '';

        if ($method === 'pick') {
            $user = $this->promptUserSelection($input, $output, $io, 'Select user to login');
            if ($user === null) {
                $io->error('No users found for this app scope.');

                return [null, ''];
            }
            if ($user->status !== UserModel::ACTIVE) {
                $io->error(sprintf('User #%d is not active (status: %s).', $user->user_id, $user->status));

                return [null, ''];
            }
            Auth::login($user, $remember);
            $token = (string) (Auth::getTokenKey() ?: '');
        } elseif ($method === 'id') {
            $id = (string) $io->ask('User id');
            $input->setOption('id', $id);
            [$user, $token] = $this->loginById($io, $id, $remember);
        } else {
            $login = (string) $io->ask('Username or email');
            $input->setOption('username', $login);
            [$user, $token] = $this->loginByCredentials($input, $io, $remember);
        }

        if ($user === null) {
            return [null, ''];
        }

        return [$user, $token];
    }

    private function shouldPersistEnv(InputInterface $input): bool
    {
        return !(bool) $input->getOption('no-env');
    }

    private function clearDevLogin(SymfonyStyle $io, bool $json): int
    {
        $ok = DevLogin::clear();

        if ($json) {
            $io->writeln(json_encode([
                'ok' => $ok,
                'cleared' => true,
                'deprecated' => 'Use user:logout instead of user:login --clear',
                'dev_login_enabled' => DevLogin::enabled(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $ok ? Command::SUCCESS : Command::FAILURE;
        }

        if (!$ok) {
            $io->error('Could not clear PINOOX_LOGIN.');

            return Command::FAILURE;
        }

        $io->success('Cleared PINOOX_LOGIN. Prefer: user:logout');

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
