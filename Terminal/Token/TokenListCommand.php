<?php

namespace Pinoox\Terminal\Token;

use Pinoox\Component\Terminal;
use Pinoox\Model\TokenModel;
use Pinoox\Terminal\Token\Concerns\ManagesCliTokens;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'token:list',
    description: 'List session tokens for an app or platform',
    aliases: ['tokens'],
)]
class TokenListCommand extends Terminal
{
    use ManagesCliTokens;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
List tokens in the current session-token scope.

Examples:
  php pinoox token:list
  php pinoox token:list com_pinoox_manager
  php pinoox token:list --user=admin --active
  php pinoox token:list --expired
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Filter by user id, username, or email')
            ->addOption('active', null, InputOption::VALUE_NONE, 'Show only active (non-expired) tokens')
            ->addOption('expired', null, InputOption::VALUE_NONE, 'Show only expired tokens')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveTokenPackage($input, $output, $io, 'List tokens for');
        $this->prepareTokenScope($package);

        $query = TokenModel::query()->orderByDesc('token_id');

        $userFilter = $input->getOption('user');
        if (is_string($userFilter) && $userFilter !== '') {
            $user = $this->resolveUser($userFilter);
            if ($user === null) {
                $io->error('User not found: ' . $userFilter);

                return Command::FAILURE;
            }

            $query->where('user_id', $user->user_id);
        }

        $now = now()->format('Y-m-d H:i:s');

        if ($input->getOption('active')) {
            $query->where(function ($builder) use ($now) {
                $builder->whereNull('expiration_date')->orWhere('expiration_date', '>=', $now);
            });
        }

        if ($input->getOption('expired')) {
            $query->whereNotNull('expiration_date')->where('expiration_date', '<', $now);
        }

        $tokens = $query->get();

        if ($input->getOption('json')) {
            $rows = $tokens->map(fn (TokenModel $token) => $this->tokenRow($token))->values()->all();
            $io->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if ($tokens->isEmpty()) {
            $io->warning('No tokens found for package: ' . $package);

            return Command::SUCCESS;
        }

        $io->title('Tokens — ' . $package);

        $table = new Table($output);
        $table->setHeaders(['ID', 'Key', 'Name', 'User', 'IP', 'Expires', 'Status']);
        foreach ($tokens as $token) {
            $table->addRow([
                $token->token_id,
                $this->maskTokenKey((string) $token->token_key),
                $token->token_name ?: '—',
                $token->user_id ?: '—',
                $token->ip ?: '—',
                $this->formatExpiration($token),
                $this->tokenStatusLabel($token),
            ]);
        }
        $table->render();

        $io->text('Total: ' . $tokens->count());

        return Command::SUCCESS;
    }
}
