<?php

namespace Pinoox\Terminal\User;

use Pinoox\Component\Terminal;
use Pinoox\Model\UserModel;
use Pinoox\Terminal\User\Concerns\ManagesCliUsers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'user:list',
    description: 'List users for an app or platform',
    aliases: ['users'],
)]

class UserListCommand extends Terminal
{
    use ManagesCliUsers;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
List users for a package.

Examples:
  php pinoox user:list com_my_shop
  php pinoox user:list --status=active
  pinx users
HELP
            )
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Filter by status')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolveUserPackage($input, $output, $io, 'List users for');
        $this->prepareUserScope($package);

        $status = $input->getOption('status');
        if (is_string($status) && $status !== '' && !in_array($status, $this->userStatuses(), true)) {
            $io->error('Invalid status. Use: ' . implode(', ', $this->userStatuses()));

            return Command::FAILURE;
        }

        $query = UserModel::query()->orderBy('user_id');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $users = $query->get();

        if ($input->getOption('json')) {
            $rows = $users->map(fn (UserModel $user) => $this->userRow($user))->values()->all();
            $io->writeln(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if ($users->isEmpty()) {
            $io->warning('No users found for package: ' . $package);

            return Command::SUCCESS;
        }

        $io->title('Users — ' . $package);

        $table = new Table($output);
        $table->setHeaders(['ID', 'Username', 'Email', 'Name', 'Status', 'Group', 'Created']);
        foreach ($users as $user) {
            $table->addRow([
                $user->user_id,
                $user->username,
                $user->email ?: '—',
                trim($user->full_name) ?: '—',
                $user->status,
                $user->group_key ?: '—',
                $user->created_at?->format('Y-m-d H:i') ?: '—',
            ]);
        }
        $table->render();

        $io->text('Total: ' . $users->count());

        return Command::SUCCESS;
    }
}
