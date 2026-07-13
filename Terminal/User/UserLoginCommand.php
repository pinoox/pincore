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
Interactive login wizard (or pass options for non-interactive use).

With --force (or when you confirm in the wizard), upserts
PINOOX_LOGIN=package:id:N in .env (one line per app; multi-app OK).

Examples:
  php pinoox user:login
  php pinoox user:login com_my_shop
  php pinoox user:login platform --id=1 --force
  php pinoox user:login --clear
  pinx user:login

.env formats (multiple lines = login per app):
  PINOOX_LOGIN=com_pinoox_manager:id:1
  PINOOX_LOGIN=com_pinoox_manager:user_id:1
  PINOOX_LOGIN=com_pinoox_manager:personal_id:1
  PINOOX_LOGIN=com_pinoox_account:username:yoosef
  PINOOX_LOGIN=com_pinoox_shop:mobile:09122220000
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp())
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Login by user id (skips password)')
            ->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'Username or email')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Plain password')
            ->addOption('remember', 'r', InputOption::VALUE_NONE, 'Use remember-me lifetime')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Persist PINOOX_LOGIN=package:id:N for auto-login')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Clear PINOOX_LOGIN from .env and storage')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('clear')) {
            return $this->clearDevLogin($io, (bool) $input->getOption('json'));
        }

        $useWizard = $input->isInteractive()
            && !$input->getOption('json')
            && !$this->hasExplicitCredentials($input);

        if ($useWizard) {
            $io->title('User login');
            $io->text('Sign in for an app scope, then optionally save PINOOX_LOGIN to .env.');
            $io->newLine();
        }

        $package = $this->resolveUserPackage($input, $output, $io, $useWizard ? 'Which app?' : 'Login user for');
        $this->prepareUserScope($package);
        $this->prepareCliRequestContext();

        $remember = (bool) $input->getOption('remember');

        try {
            if ($useWizard) {
                [$user, $token, $persist] = $this->runLoginWizard($input, $output, $io, $package, $remember);
            } else {
                $userIdOption = $input->getOption('id');
                if ($userIdOption !== null && $userIdOption !== '') {
                    [$user, $token] = $this->loginById($io, $userIdOption, $remember);
                } else {
                    [$user, $token] = $this->loginByCredentials($input, $io, $remember);
                }
                $persist = $this->shouldPersistToken($input, $io, wizardAsked: false);
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
            'pinoox_login' => DevLogin::expression(),
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
            ['.env' => $persisted ? 'PINOOX_LOGIN=' . DevLogin::expression() : 'not updated'],
        );

        if ($persisted) {
            $io->note('Auto-login is active while PINOOX_LOGIN is set. Clear with: user:login --clear');
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
     * @return array{0: ?UserModel, 1: string, 2: bool}
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

                return [null, '', false];
            }
            if ($user->status !== UserModel::ACTIVE) {
                $io->error(sprintf('User #%d is not active (status: %s).', $user->user_id, $user->status));

                return [null, '', false];
            }
            $token = (string) (Auth::login($user, $remember) ?? '');
        } elseif ($method === 'id') {
            $id = (string) $io->ask('User id');
            [$user, $token] = $this->loginById($io, $id, $remember);
        } else {
            $login = (string) $io->ask('Username or email');
            $input->setOption('username', $login);
            [$user, $token] = $this->loginByCredentials($input, $io, $remember);
        }

        if ($user === null) {
            return [null, '', false];
        }

        $io->section('.env auto-login');
        $persist = $this->shouldPersistToken($input, $io, wizardAsked: true);

        return [$user, $token, $persist];
    }

    private function shouldPersistToken(InputInterface $input, SymfonyStyle $io, bool $wizardAsked): bool
    {
        if ((bool) $input->getOption('force')) {
            return true;
        }

        if ($wizardAsked && $input->isInteractive()) {
            return $io->confirm(
                'Update .env with PINOOX_LOGIN=package:id:… for automatic login?',
                true,
            );
        }

        if ($input->isInteractive() && !$input->getOption('json')) {
            return $io->confirm(
                'Update .env with PINOOX_LOGIN=package:id:… for automatic login?',
                true,
            );
        }

        return DevLogin::enabled();
    }

    private function clearDevLogin(SymfonyStyle $io, bool $json): int
    {
        $ok = DevLogin::clear();

        if ($json) {
            $io->writeln(json_encode([
                'ok' => $ok,
                'cleared' => true,
                'dev_login_enabled' => DevLogin::enabled(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $ok ? Command::SUCCESS : Command::FAILURE;
        }

        if (!$ok) {
            $io->error('Could not clear PINOOX_LOGIN.');

            return Command::FAILURE;
        }

        $io->success('Cleared PINOOX_LOGIN.');

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
