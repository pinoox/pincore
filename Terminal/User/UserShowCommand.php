<?php

namespace Pinoox\Terminal\User;

use Pinoox\Component\Terminal;
use Pinoox\Terminal\User\Concerns\ManagesCliUsers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'user:show',
    description: 'Show details for a user',
)]

class UserShowCommand extends Terminal
{
    use ManagesCliUsers;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Show a single user by id, username, or email.

Examples:
  php pinoox user:show com_my_shop admin
  pinx user:show 12
HELP
            )
            ->addArgument('user', InputArgument::REQUIRED, 'User id, username, or email')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveUserPackageInput($input, $output, $io, 'Show user for');
        $this->prepareUserScope($package);

        $identifier = $this->resolveUserIdentifier($input);
        $user = $this->resolveUser($identifier);

        if ($user === null) {
            $io->error('User not found: ' . $identifier);

            return Command::FAILURE;
        }

        $user->load('roles');
        $row = $this->userRow($user, includeRoles: true);

        if ($input->getOption('json')) {
            $io->writeln(json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->title('User #' . $user->user_id);
        $io->definitionList(
            ['Package' => $package],
            ['Username' => (string) $user->username],
            ['Email' => (string) ($user->email ?: '—')],
            ['Name' => trim($user->full_name) ?: '—'],
            ['Status' => (string) $user->status],
            ['Group' => (string) ($user->group_key ?: '—')],
            ['Mobile' => (string) ($user->mobile ?: '—')],
            ['Roles' => $row['roles'] === [] ? '—' : implode(', ', $row['roles'])],
            ['Created' => (string) ($user->created_at?->format('Y-m-d H:i:s') ?: '—')],
        );

        return Command::SUCCESS;
    }
}
