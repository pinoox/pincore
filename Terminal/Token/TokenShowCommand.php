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
    name: 'token:show',
    description: 'Show details for a token',
)]
class TokenShowCommand extends Terminal
{
    use ManagesCliTokens;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Show token details by id or token_key.

Examples:
  php pinoox token:show 12
  php pinoox token:show com_pinoox_manager 12
  php pinoox token:show --reveal abc123...
HELP
            )
            ->addArgument('token', InputArgument::OPTIONAL, 'Token id or token_key. Leave empty to pick from the list.')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('reveal', null, InputOption::VALUE_NONE, 'Show full token key')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveTokenPackageInput($input, $output, $io, 'Show token for');
        $this->prepareTokenScope($package);

        $token = $this->resolveTokenInput($input, $output, $io, 'Select token');

        if ($token === null) {
            $io->error('Token not found.');

            return Command::FAILURE;
        }

        $row = $this->tokenRow($token, revealKey: (bool) $input->getOption('reveal'));

        if ($input->getOption('json')) {
            $io->writeln(json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title('Token #' . $token->token_id);
        $io->definitionList(
            ['Package context' => $package],
            ['Key' => (string) $row['token_key']],
            ['Name' => (string) ($token->token_name ?: '—')],
            ['User id' => (string) ($token->user_id ?: '—')],
            ['App scope' => (string) ($token->app ?: '—')],
            ['Status' => (string) $row['status']],
            ['Expires' => (string) $row['expiration_date']],
            ['IP' => (string) ($token->ip ?: '—')],
            ['User agent' => (string) ($token->user_agent ?: '—')],
            ['Remote URL' => (string) ($token->remote_url ?: '—')],
            ['Created' => (string) ($row['created_at'] ?: '—')],
            ['Updated' => (string) ($row['updated_at'] ?: '—')],
            ['Data' => $token->token_data === null
                ? '—'
                : json_encode($token->token_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );

        return Command::SUCCESS;
    }
}
