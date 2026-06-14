<?php

namespace Pinoox\Terminal\Token;

use Pinoox\Component\Terminal;
use Pinoox\Component\Token;
use Pinoox\Terminal\Token\Concerns\ManagesCliTokens;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'token:purge',
    description: 'Delete expired tokens',
    aliases: ['token:purge-expired'],
)]
class TokenPurgeCommand extends Terminal
{
    use ManagesCliTokens;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Delete expired tokens (global cleanup across all app scopes).

Examples:
  php pinoox token:purge
  php pinoox token:purge --force
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, 'Unused; purge is global. Kept for consistency.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $this->prepareTokenScope('platform');

        if (!$input->getOption('force') && !$io->confirm(
            'Delete all expired tokens across the platform?',
            false,
        )) {
            $io->warning('Purge canceled.');

            return Command::SUCCESS;
        }

        $count = Token::deleteAllExpired();

        $io->success(sprintf('Purged %d expired token(s).', $count));

        return Command::SUCCESS;
    }
}
