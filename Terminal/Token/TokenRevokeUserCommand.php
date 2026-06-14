<?php

namespace Pinoox\Terminal\Token;

use Pinoox\Component\Terminal;
use Pinoox\Terminal\Token\Concerns\ManagesCliTokens;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'token:revoke-user',
    description: 'Revoke all tokens for a user',
    aliases: ['token:revoke-all'],
)]
class TokenRevokeUserCommand extends Terminal
{
    use ManagesCliTokens;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Revoke (delete) all tokens for a user in the current scope.

Examples:
  php pinoox token:revoke-user admin --force
  php pinoox token:revoke-all com_pinoox_manager admin --force
HELP
            )
            ->addArgument('user', InputArgument::OPTIONAL, 'User id, username, or email')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveTokenPackageInput($input, $output, $io, 'Revoke tokens for');
        $this->prepareTokenScope($package);

        try {
            $userId = $this->resolveTokenUserId($input, $output, $io);
            $user = $this->resolveUser((string) $userId);
            $username = $user?->username ?? ('#' . $userId);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!$input->getOption('force') && !$io->confirm(
            sprintf('Revoke all tokens for user %s in scope %s?', $username, $package),
            false,
        )) {
            $io->warning('Revoke canceled.');

            return Command::SUCCESS;
        }

        $count = $this->revokeUserTokens($userId);

        $io->success(sprintf('Revoked %d token(s) for user %s.', $count, $username));

        return Command::SUCCESS;
    }
}
