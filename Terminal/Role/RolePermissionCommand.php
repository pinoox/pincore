<?php



namespace Pinoox\Terminal\Role;



use Pinoox\Component\Terminal;

use Pinoox\Terminal\Concerns\SelectsPackage;

use Pinoox\Terminal\Role\Concerns\ManagesCliRoles;

use Symfony\Component\Console\Attribute\AsCommand;

use Symfony\Component\Console\Command\Command;

use Symfony\Component\Console\Input\InputArgument;

use Symfony\Component\Console\Input\InputInterface;

use Symfony\Component\Console\Input\InputOption;

use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\Console\Style\SymfonyStyle;



#[AsCommand(

    name: 'role:permission',

    description: 'Attach or sync permissions on a role',

    aliases: ['role:permissions'],

)]



class RolePermissionCommand extends Terminal

{

    use SelectsPackage;

    use ManagesCliRoles;



    protected function configure(): void

    {

        $this

            ->setHelp(

                <<<'HELP'

Manage permissions assigned to a role.



Examples:

  php pinoox permission:create com_my_shop --key=manager.* --name="Manager panel"

  php pinoox role:permission admin --permission=manager.* --permission=posts.edit

  php pinoox role:permission admin --permission=posts.edit --sync

  php pinoox role:permission admin --permission=legacy --detach

HELP

            )

            ->addArgument('role', InputArgument::OPTIONAL, 'Role id or role_key. Leave empty to pick from the list.')

            ->addArgument('package', InputArgument::OPTIONAL, $this->packageArgumentHelp(optional: true))

            ->addOption('permission', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'permission_key (repeatable)')

            ->addOption('sync', null, InputOption::VALUE_NONE, 'Replace existing permissions')

            ->addOption('detach', null, InputOption::VALUE_NONE, 'Remove permission(s) instead of attaching');

    }



    protected function execute(InputInterface $input, OutputInterface $output): int

    {

        parent::execute($input, $output);



        $io = new SymfonyStyle($input, $output);



        try {

            $package = $this->resolveRolePackageInput($input, $output, $io, 'Manage role permissions for');

            $this->prepareRoleScope($package);



            $role = $this->resolveRoleInput($input, $output, $io, 'Select role');

        } catch (\RuntimeException $e) {

            $io->error($e->getMessage());



            return Command::FAILURE;

        }



        if ($role === null) {

            $io->error('Role not found.');



            return Command::FAILURE;

        }



        /** @var list<string> $permissionKeys */

        $permissionKeys = $input->getOption('permission');

        $permissionKeys = array_values(array_filter(array_map('strval', $permissionKeys)));



        if ($permissionKeys === []) {

            if ($input->isInteractive()) {

                $permissionKeys = [(string) $io->ask('Permission key')];

            } else {

                $io->error('Pass at least one --permission=permission_key.');



                return Command::FAILURE;

            }

        }



        try {

            if ($input->getOption('detach')) {

                $this->detachPermissionsFromRole($role, $permissionKeys);

                $action = 'detached from';

            } elseif ($input->getOption('sync')) {

                $this->assignPermissionsToRole($role, $permissionKeys, sync: true);

                $action = 'synced to';

            } else {

                $this->assignPermissionsToRole($role, $permissionKeys);

                $action = 'attached to';

            }

        } catch (\InvalidArgumentException $e) {

            $io->error($e->getMessage());



            return Command::FAILURE;

        }



        $io->success(sprintf(

            'Permissions [%s] %s role #%d (%s).',

            implode(', ', $permissionKeys),

            $action,

            $role->role_id,

            $role->role_key,

        ));



        return Command::SUCCESS;

    }

}


