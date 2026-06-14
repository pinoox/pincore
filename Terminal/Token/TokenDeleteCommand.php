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
    name: 'token:delete',
    description: 'Delete (revoke) a token',
    aliases: ['token:revoke'],
)]
class TokenDeleteCommand extends Terminal
{
    use ManagesCliTokens;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Delete a single token by id or token_key.

Examples:
  php pinoox token:delete 12 --force
  php pinoox token:revoke abcdef1234567890 --force
HELP
            )
            ->addArgument('token', InputArgument::OPTIONAL, 'Token id or token_key. Leave empty to pick from the list.')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveTokenPackageInput($input, $output, $io, 'Delete token for');
        $this->prepareTokenScope($package);

        $token = $this->resolveTokenInput($input, $output, $io, 'Select token to delete');

        if ($token === null) {
            $io->error('Token not found.');

            return Command::FAILURE;
        }

        if (!$input->getOption('force') && !$io->confirm(
            sprintf('Delete token #%d (%s)?', $token->token_id, $this->maskTokenKey((string) $token->token_key)),
            false,
        )) {
            $io->warning('Delete canceled.');

            return Command::SUCCESS;
        }

        if (!Token::delete((string) $token->token_key)) {
            $io->error('Failed to delete token.');

            return Command::FAILURE;
        }

        $io->success('Token #' . $token->token_id . ' deleted.');

        return Command::SUCCESS;
    }
}
