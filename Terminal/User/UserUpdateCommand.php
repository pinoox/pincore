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
    name: 'user:update',
    description: 'Update profile fields for a user',
)]

class UserUpdateCommand extends Terminal
{
    use ManagesCliUsers;
    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Update user profile fields.

Find users by id, username, email, mobile, or personal id. If the identifier
matches more than one user, you will be asked to pick the user id. Run without
arguments to pick a user from the list interactively, then edit fields.

Examples:
  php pinoox user:update
  php pinoox user:update admin --email=new@example.com --mobile=09120000000
  php pinoox user:update 09120000000 --fname=Ali
  php pinoox user:update admin --meta theme=dark --meta locale=fa
  php pinoox user:update admin --metadata='{"theme":"dark","locale":"fa"}'
  php pinoox user:update admin --set meta.theme=dark --set meta.locale=fa
  php pinoox user:update admin --meta old_key= --set meta.old_key=
Metadata is merged with existing values. Use empty value to remove a key.
Field aliases for --set: first-name, last-name, group, phone, personal-id, meta.key
HELP
            )
            ->addArgument('user', InputArgument::OPTIONAL, 'User id, username, email, mobile, or personal id. Leave empty to pick from the list.')
            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))
            ->addOption('set', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Set field=value (repeatable)')
            ->addOption('meta', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Set metadata key=value (repeatable, merged)')
            ->addOption('metadata', 'm', InputOption::VALUE_REQUIRED, 'Metadata as JSON object (merged with current)')
            ->addOption('username', 'u', InputOption::VALUE_REQUIRED, 'New username')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'New email')
            ->addOption('fname', null, InputOption::VALUE_REQUIRED, 'First name')
            ->addOption('lname', null, InputOption::VALUE_REQUIRED, 'Last name')
            ->addOption('mobile', null, InputOption::VALUE_REQUIRED, 'Mobile number')
            ->addOption('group-key', null, InputOption::VALUE_REQUIRED, 'Group key')
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Status: active, inactive, suspend, pending')
            ->addOption('personal-id', null, InputOption::VALUE_REQUIRED, 'Personal ID');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);
        $io = new SymfonyStyle($input, $output);

        $useWizard = $input->isInteractive() && $this->resolveUserIdentifier($input) === '';

        if ($useWizard) {
            $io->title('Update user');
            $io->text('Pick a user from the list or pass an id, username, email, mobile, or personal id.');
            $io->newLine();
        }

        try {
            $package = $this->resolveUserPackageInput($input, $output, $io, 'Update user for');
            $this->prepareUserScope($package);
            $user = $this->resolveUserInput($input, $output, $io, 'Select user to update');
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        if ($user === null) {
            $io->error('User not found.');
            return Command::FAILURE;
        }
        try {
            $fields = $this->collectUserUpdateFields($input);
            if ($fields === [] && $input->isInteractive()) {
                $fields = $this->promptUserFieldUpdates($io, $user);
            }
            if ($fields === []) {
                $io->warning('Nothing to update. Pass --set, --meta, --metadata, or field options, or run interactively.');
                return Command::SUCCESS;
            }
            $this->applyUserFieldUpdates($user, $fields);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Failed to update user: ' . $e->getMessage());
            return Command::FAILURE;
        }
        $user->refresh();
        $io->success('User #' . $user->user_id . ' updated.');
        $io->listing($this->describeUserFieldUpdates($fields));
        return Command::SUCCESS;
    }
}
