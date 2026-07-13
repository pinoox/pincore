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
    description: 'Clear PINOOX_LOGIN auto-login and end the current auth session',
)]
class UserLogoutCommand extends Terminal
{
    use ManagesCliUsers;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Clear PINOOX_LOGIN from .env for an app (or all apps) and log out the current session.

Examples:
  php pinoox user:logout com_pinoox_manager
  php pinoox user:logout --all
  php pinoox user:logout --json
  pinx user:logout
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Clear every PINOOX_LOGIN line')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $clearAll = (bool) $input->getOption('all');
        $package = '';

        if (!$clearAll) {
            $package = $this->resolveUserPackageInput($input, $output, $io, 'Logout PINOOX_LOGIN for');
            $this->prepareUserScope($package);
            $this->prepareCliRequestContext();
        }

        $before = array_map(
            static fn (array $entry): string => DevLogin::format($entry),
            DevLogin::parseAll(),
        );

        try {
            if (Auth::check()) {
                Auth::logout();
            }
        } catch (\Throwable) {
            // Env/session may already be empty in CLI.
        }

        $ok = $clearAll ? DevLogin::clear() : DevLogin::forget($package);
        $after = array_map(
            static fn (array $entry): string => DevLogin::format($entry),
            DevLogin::parseAll(),
        );

        $payload = [
            'ok' => $ok,
            'cleared_all' => $clearAll,
            'package' => $clearAll ? null : $package,
            'before' => $before,
            'after' => $after,
            'pinoox_login' => $clearAll ? '' : DevLogin::expression($package),
            'dev_login_enabled' => DevLogin::enabled(),
        ];

        if ($input->getOption('json')) {
            $io->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $ok ? Command::SUCCESS : Command::FAILURE;
        }

        if (!$ok) {
            $io->error('Could not clear PINOOX_LOGIN.');

            return Command::FAILURE;
        }

        if ($clearAll) {
            $io->success('Cleared all PINOOX_LOGIN entries.');
        } else {
            $io->success(sprintf('Cleared PINOOX_LOGIN for %s.', $package));
        }

        if ($after !== []) {
            $io->note('Remaining: ' . implode(', ', $after));
        }

        return Command::SUCCESS;
    }
}
