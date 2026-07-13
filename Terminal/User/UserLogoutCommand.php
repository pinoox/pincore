<?php

namespace Pinoox\Terminal\User;

use Pinoox\Component\Terminal;
use Pinoox\Component\User\DevLogin;
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
    name: 'user:logout',
    description: 'End the current auth session (token/cookie)',
)]
class UserLogoutCommand extends Terminal
{
    use ManagesCliUsers;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Log out the current auth session for an app scope.

With --force, removes PINOOX_LOGIN_TOKEN from .env.
Does not change PINOOX_LOGIN (manual declarative auto-login).

Examples:
  php pinoox user:logout com_pinoox_manager
  php pinoox user:logout --force
  php pinoox user:logout --json
  pinx user:logout --force
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Remove PINOOX_LOGIN_TOKEN from .env')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveUserPackageInput($input, $output, $io, 'Logout for');
        $this->prepareUserScope($package);
        $this->prepareCliRequestContext();

        $wasLoggedIn = false;
        try {
            $wasLoggedIn = Auth::check();
            if ($wasLoggedIn) {
                Auth::logout();
            }
        } catch (\Throwable $e) {
            if ($input->getOption('json')) {
                $io->writeln(json_encode([
                    'ok' => false,
                    'message' => $e->getMessage(),
                    'package' => $package,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return Command::FAILURE;
            }

            $io->error('Logout failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $clearedToken = false;
        if ((bool) $input->getOption('force')) {
            $clearedToken = DevLogin::clearToken();
        }

        $payload = [
            'ok' => true,
            'package' => $package,
            'was_logged_in' => $wasLoggedIn,
            'logged_out' => true,
            'pinoox_login_token_cleared' => $clearedToken,
        ];

        if ($input->getOption('json')) {
            $io->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->success($wasLoggedIn
            ? sprintf('Logged out for %s.', $package)
            : sprintf('No active session for %s (ok).', $package));

        if ((bool) $input->getOption('force')) {
            $io->note($clearedToken
                ? 'Removed PINOOX_LOGIN_TOKEN from .env.'
                : 'PINOOX_LOGIN_TOKEN was already absent (or could not be removed).');
        }

        return Command::SUCCESS;
    }
}
