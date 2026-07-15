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
Show a single user by id, username, email, mobile, or personal id.

Run without arguments for an interactive wizard. If the identifier matches
more than one user, you will be asked to pick the user id.

Examples:
  php pinoox user:show
  php pinoox user:show com_my_shop admin
  php pinoox user:show 09120000000
  pinx user:show 12 --json
HELP
            )
            ->addArgument('user', InputArgument::OPTIONAL, 'User id, username, email, mobile, or personal id')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);

        $useWizard = $input->isInteractive() && $this->resolveUserIdentifier($input) === '';

        if ($useWizard) {
            $io->title('Show user');
            $io->text('Find a user by id, username, email, mobile, or personal id.');
            $io->newLine();
        }

        $package = $this->resolveUserPackageInput($input, $output, $io, 'Show user for');
        $this->prepareUserScope($package);

        try {
            $user = $this->resolveCliUserFromInput(
                $input,
                $output,
                $io,
                'Multiple users found — which one?',
            );
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($user === null) {
            $io->error('User not found.');

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
            ['Personal ID' => (string) ($user->personal_id ?: '—')],
            ['Roles' => $row['roles'] === [] ? '—' : implode(', ', $row['roles'])],
            ['Created' => (string) ($user->created_at?->format('Y-m-d H:i:s') ?: '—')],
        );

        return Command::SUCCESS;
    }
}
