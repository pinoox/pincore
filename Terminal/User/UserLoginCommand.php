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
Authenticate a user and print a token for cookie/JWT/browser apply.

With --force, writes PINOOX_LOGIN_TOKEN to .env (CLI token auto-login).
Does not write PINOOX_LOGIN (optional manual declarative auto-login).

Examples:
  php pinoox user:login
  php pinoox user:login com_my_shop --id=1
  php pinoox user:login --id=1 --force
  pinx user:login --id=1 --force
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp())
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Login by user id (skips password)')
            ->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'Username or email')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Plain password')
            ->addOption('remember', 'r', InputOption::VALUE_NONE, 'Use remember-me lifetime')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Write PINOOX_LOGIN_TOKEN to .env')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);

        $useWizard = $input->isInteractive()
            && !$input->getOption('json')
            && !$this->hasExplicitCredentials($input);

        if ($useWizard) {
            $io->title('User login');
            $io->text('Signs in and prints a session/JWT token for browser apply.');
            $io->newLine();
        }

        $package = $this->resolveUserPackage($input, $output, $io, $useWizard ? 'Which app?' : 'Login user for');
        $this->prepareUserScope($package);
        $this->prepareCliRequestContext();

        $remember = (bool) $input->getOption('remember');

        try {
            if ($useWizard) {
                [$user, $token] = $this->runLoginWizard($input, $output, $io, $package, $remember);
            } else {
                $userIdOption = $input->getOption('id');
                if ($userIdOption !== null && $userIdOption !== '') {
                    [$user, $token] = $this->loginById($io, $userIdOption, $remember);
                } else {
                    [$user, $token] = $this->loginByCredentials($input, $io, $remember);
                }
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

        if ((bool) $input->getOption('force') && $token !== '') {
            $persisted = DevLogin::rememberToken($token);
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
            'pinoox_login_token' => $persisted,
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
            ['.env' => $persisted ? 'PINOOX_LOGIN_TOKEN updated' : 'not updated'],
        );

        if ($persisted) {
            $io->note('PINOOX_LOGIN_TOKEN is set. Clear with: user:logout --force');
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
            $token = (string) (Auth::login($user, $remember) ?? '');
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
        $username = (string) ($input->getOption('username') ?? '');
        if ($username === '') {
            $io->error('Username or email is required (or pass --id).');

            return [null, ''];
        }

        $password = (string) ($input->getOption('password') ?? '');
        if ($password === '' && $input->isInteractive()) {
            $question = new Question('Password');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password = (string) $io->askQuestion($question);
        }

        if ($password === '') {
            $io->error('Password is required.');

            return [null, ''];
        }

        $result = Auth::attemptResult([
            'username' => $username,
            'password' => $password,
        ], $remember);

        if (!$result->success) {
            $io->error($result->message ?: 'Invalid credentials.');

            return [null, ''];
        }

        return [$result->user, (string) ($result->token ?? '')];
    }
}
