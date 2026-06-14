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
    name: 'token:create',
    description: 'Create a session token for a user',
    aliases: ['make:token'],
)]
class TokenCreateCommand extends Terminal
{
    use ManagesCliTokens;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Create a session token in the current token scope.

Examples:
  php pinoox token:create com_pinoox_manager --user=admin
  php pinoox token:create --user=admin --name="API access" --lifetime=7 --unit=day
  php pinoox token:create --user=1 --data='{"scope":"api"}'
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'User id, username, or email')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Token label')
            ->addOption('data', null, InputOption::VALUE_REQUIRED, 'Scalar payload stored as {"value": "..."}')
            ->addOption('json', null, InputOption::VALUE_REQUIRED, 'Token data as JSON object')
            ->addOption('lifetime', 'l', InputOption::VALUE_REQUIRED, 'Lifetime amount (default: 30)', '30')
            ->addOption('unit', null, InputOption::VALUE_REQUIRED, 'Lifetime unit: min, hour, day', 'day')
            ->addOption('key', 'k', InputOption::VALUE_REQUIRED, 'Custom token_key (optional)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveTokenPackage($input, $output, $io, 'Create token for');
        $this->prepareTokenScope($package);

        try {
            $userId = $this->resolveTokenUserId($input, $output, $io);
            $this->applyTokenLifetime($input);
            $data = $this->parseTokenDataInput(
                is_string($input->getOption('json')) ? $input->getOption('json') : null,
                is_string($input->getOption('data')) ? $input->getOption('data') : null,
            );
            $name = trim((string) ($input->getOption('name') ?: ''));
            $customKey = trim((string) ($input->getOption('key') ?: ''));

            $token = $this->createToken(
                $data,
                $userId,
                $name !== '' ? $name : null,
                $customKey !== '' ? $customKey : null,
            );
            $tokenKey = (string) $token->token_key;
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Failed to create token: ' . $e->getMessage());

            return Command::FAILURE;
        }

        if ($token === null) {
            $io->error('Token was created but could not be loaded.');

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Token #%d created for user #%d (context: %s).',
            $token->token_id,
            $userId,
            $package,
        ));

        $io->definitionList(
            ['Token key' => $tokenKey],
            ['Name' => (string) ($token->token_name ?: '—')],
            ['Expires' => $this->formatExpiration($token)],
        );

        return Command::SUCCESS;
    }
}
