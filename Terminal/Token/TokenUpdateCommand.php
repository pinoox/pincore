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
    name: 'token:update',
    description: 'Update token name, data, or extend expiration',
)]
class TokenUpdateCommand extends Terminal
{
    use ManagesCliTokens;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Update token metadata or extend its lifetime.

Examples:
  php pinoox token:update 12 --name="Mobile app"
  php pinoox token:update 12 --json='{"scope":"panel"}'
  php pinoox token:update 12 --extend --lifetime=7 --unit=day
HELP
            )
            ->addArgument('token', InputArgument::OPTIONAL, 'Token id or token_key. Leave empty to pick from the list.')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Token label')
            ->addOption('data', null, InputOption::VALUE_REQUIRED, 'Scalar payload stored as {"value": "..."}')
            ->addOption('json', null, InputOption::VALUE_REQUIRED, 'Replace token_data with JSON')
            ->addOption('extend', null, InputOption::VALUE_NONE, 'Extend expiration from now')
            ->addOption('lifetime', 'l', InputOption::VALUE_REQUIRED, 'Lifetime amount when using --extend', '30')
            ->addOption('unit', null, InputOption::VALUE_REQUIRED, 'Lifetime unit: min, hour, day', 'day');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveTokenPackageInput($input, $output, $io, 'Update token for');
        $this->prepareTokenScope($package);

        $token = $this->resolveTokenInput($input, $output, $io, 'Select token to update');

        if ($token === null) {
            $io->error('Token not found.');

            return Command::FAILURE;
        }

        $changes = [];
        $dirty = false;

        try {
            $name = trim((string) ($input->getOption('name') ?: ''));
            if ($name !== '') {
                $token->token_name = $name;
                $dirty = true;
                $changes[] = 'name';
            }

            $json = $input->getOption('json');
            $data = $input->getOption('data');
            if ((is_string($json) && $json !== '') || (is_string($data) && $data !== '')) {
                $token->token_data = $this->parseTokenDataInput(
                    is_string($json) ? $json : null,
                    is_string($data) ? $data : null,
                );
                $dirty = true;
                $changes[] = 'data';
            }

            if ($input->getOption('extend')) {
                $this->applyTokenLifetime($input);
                Token::updateLifetime((string) $token->token_key);
                $changes[] = 'expiration';
            }
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($dirty) {
            $token->save();
        }

        if ($changes === []) {
            $io->warning('No changes provided.');

            return Command::SUCCESS;
        }

        $token->refresh();

        $io->success(sprintf('Token #%d updated (%s).', $token->token_id, implode(', ', $changes)));
        $io->definitionList(
            ['Name' => (string) ($token->token_name ?: '—')],
            ['Expires' => $this->formatExpiration($token)],
            ['Status' => $this->tokenStatusLabel($token)],
        );

        return Command::SUCCESS;
    }
}
