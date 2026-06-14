<?php



namespace Pinoox\Terminal\Role\Concerns;



use Pinoox\Component\Transport\TransportRuntime;

use Pinoox\Model\PermissionModel;

use Pinoox\Model\RoleModel;

use Pinoox\Model\UserModel;

use Pinoox\Portal\Database\DB;

use Pinoox\Support\Platform;

use Symfony\Component\Console\Helper\Table;

use Symfony\Component\Console\Input\InputInterface;

use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\Console\Question\Question;

use Symfony\Component\Console\Style\SymfonyStyle;



trait ManagesCliRoles

{

    protected function prepareRoleScope(string $package): void

    {

        DB::ensureRegistered();

        RoleModel::clearBootedModels();

        PermissionModel::clearBootedModels();

        UserModel::clearBootedModels();



        TransportRuntime::use($package);

    }



    protected function looksLikeAccessPackage(string $value): bool

    {

        return $value === Platform::PACKAGE || str_starts_with($value, 'com_');

    }



    protected function resolveRolePackageInput(

        InputInterface $input,

        OutputInterface $output,

        SymfonyStyle $io,

        string $sectionTitle = 'Manage roles for',

    ): string {

        $explicitPackage = (string) ($input->getArgument('package') ?? '');



        if ($explicitPackage !== '' && $this->looksLikeAccessPackage($explicitPackage)) {

            return $explicitPackage;

        }



        return $this->resolvePackageRequired($input, $output, $io, [

            'sectionTitle' => $sectionTitle,

        ]);

    }



    protected function resolveRoleIdentifier(InputInterface $input): string

    {

        return trim((string) $input->getArgument('role'));

    }



    protected function resolveRole(string $identifier): ?RoleModel

    {

        if ($identifier === '') {

            return null;

        }



        if (ctype_digit($identifier)) {

            return RoleModel::query()->where('role_id', (int) $identifier)->first();

        }



        return RoleModel::query()->where('role_key', $identifier)->first();

    }



    protected function resolveRoleInput(

        InputInterface $input,

        OutputInterface $output,

        SymfonyStyle $io,

        string $sectionTitle = 'Select role',

    ): ?RoleModel {

        $identifier = $this->resolveRoleIdentifier($input);



        if ($identifier !== '') {

            return $this->resolveRole($identifier);

        }



        if (!$input->isInteractive()) {

            throw new \RuntimeException('Role is required in non-interactive mode.');

        }



        return $this->promptRoleSelection($input, $output, $io, $sectionTitle);

    }



    protected function promptRoleSelection(

        InputInterface $input,

        OutputInterface $output,

        SymfonyStyle $io,

        string $sectionTitle = 'Select role',

    ): ?RoleModel {

        $roles = RoleModel::query()->orderBy('role_key')->get();



        if ($roles->isEmpty()) {

            return null;

        }



        $io->section($sectionTitle);



        $table = new Table($output);

        $table->setHeaders(['ID', 'Key', 'Name', 'Description']);

        foreach ($roles as $role) {

            $table->addRow([

                $role->role_id,

                $role->role_key,

                $role->name ?: '—',

                $role->description ?: '—',

            ]);

        }

        $table->render();



        $candidates = [];

        foreach ($roles as $role) {

            $candidates[] = (string) $role->role_id;

            $candidates[] = (string) $role->role_key;

        }



        $question = new Question('Role id or role_key: ');

        $question->setAutocompleterValues(array_values(array_unique($candidates)));

        $question->setValidator(function ($answer) {

            $answer = trim((string) $answer);

            if ($answer === '') {

                throw new \RuntimeException('Role is required.');

            }



            $role = $this->resolveRole($answer);

            if ($role === null) {

                throw new \RuntimeException('Role not found: ' . $answer);

            }



            return $role;

        });



        $selected = $this->getHelper('question')->ask($input, $output, $question);



        return $selected instanceof RoleModel ? $selected : null;

    }



    /**

     * @return array<string, mixed>

     */

    protected function roleRow(RoleModel $role, bool $withPermissions = false, bool $withUsers = false): array

    {

        $row = [

            'role_id' => $role->role_id,

            'role_key' => $role->role_key,

            'name' => $role->name,

            'description' => $role->description,

            'app' => $role->app,

            'created_at' => $role->created_at?->format('Y-m-d H:i:s'),

        ];



        if ($withPermissions) {

            $row['permissions'] = $role->permissions()->pluck('permission_key')->all();

        }



        if ($withUsers) {

            $row['users'] = $role->users()->count();

        }



        return $row;

    }



    /**

     * @param list<string> $roleKeys

     * @return array{map: array<string, int>, missing: list<string>}

     */

    protected function resolveRoleIdsByKeys(array $roleKeys): array

    {

        $roleKeys = array_values(array_unique(array_filter(array_map('strval', $roleKeys))));



        if ($roleKeys === []) {

            return ['map' => [], 'missing' => []];

        }



        $map = RoleModel::query()

            ->whereIn('role_key', $roleKeys)

            ->pluck('role_id', 'role_key')

            ->all();



        return [

            'map' => $map,

            'missing' => array_values(array_diff($roleKeys, array_keys($map))),

        ];

    }



    /**

     * @param list<string> $roleKeys

     */

    protected function assignRolesToUser(UserModel $user, array $roleKeys, bool $sync = false): void

    {

        ['map' => $map, 'missing' => $missing] = $this->resolveRoleIdsByKeys($roleKeys);



        if ($missing !== []) {

            throw new \InvalidArgumentException('Role(s) not found: ' . implode(', ', $missing));

        }



        $ids = array_values($map);



        if ($sync) {

            $user->roles()->sync($ids);

            return;

        }



        $user->roles()->syncWithoutDetaching($ids);

    }



    /**

     * @param list<string> $roleKeys

     */

    protected function detachRolesFromUser(UserModel $user, array $roleKeys): void

    {

        ['map' => $map, 'missing' => $missing] = $this->resolveRoleIdsByKeys($roleKeys);



        if ($missing !== []) {

            throw new \InvalidArgumentException('Role(s) not found: ' . implode(', ', $missing));

        }



        $user->roles()->detach(array_values($map));

    }



    /**

     * @return list<string>

     */

    protected function promptRoleKeys(SymfonyStyle $io, UserModel $user, bool $allowMultiple = true): array

    {

        $roles = RoleModel::query()->orderBy('role_key')->get();

        if ($roles->isEmpty()) {

            throw new \RuntimeException('No roles found in this package scope. Create one with: php pinoox role:create');

        }



        $current = $user->roles()->pluck('role_key')->all();

        $io->writeln('Current roles: ' . ($current === [] ? '—' : implode(', ', $current)));



        $choices = $roles->pluck('name', 'role_key')->map(

            fn ($name, $key) => $key . ($name ? ' (' . $name . ')' : ''),

        )->all();



        if ($allowMultiple) {

            $selected = $io->choice(

                'Select role(s)',

                array_values($choices),

                null,

                null,

                true,

            );



            $keys = [];

            foreach ((array) $selected as $label) {

                $keys[] = explode(' ', (string) $label, 2)[0];

            }



            return $keys;

        }



        $selected = (string) $io->choice('Select role', array_values($choices));



        return [explode(' ', $selected, 2)[0]];

    }



    /**

     * @param list<string> $permissionKeys

     * @return array{map: array<string, int>, missing: list<string>}

     */

    protected function resolvePermissionIdsByKeys(array $permissionKeys): array

    {

        $permissionKeys = array_values(array_unique(array_filter(array_map('strval', $permissionKeys))));



        if ($permissionKeys === []) {

            return ['map' => [], 'missing' => []];

        }



        $map = PermissionModel::query()

            ->whereIn('permission_key', $permissionKeys)

            ->pluck('permission_id', 'permission_key')

            ->all();



        return [

            'map' => $map,

            'missing' => array_values(array_diff($permissionKeys, array_keys($map))),

        ];

    }



    /**

     * @param list<string> $permissionKeys

     */

    protected function assignPermissionsToRole(RoleModel $role, array $permissionKeys, bool $sync = false): void

    {

        ['map' => $map, 'missing' => $missing] = $this->resolvePermissionIdsByKeys($permissionKeys);



        if ($missing !== []) {

            throw new \InvalidArgumentException('Permission(s) not found: ' . implode(', ', $missing));

        }



        $ids = array_values($map);



        if ($sync) {

            $role->permissions()->sync($ids);

            return;

        }



        $role->permissions()->syncWithoutDetaching($ids);

    }



    /**

     * @param list<string> $permissionKeys

     */

    protected function detachPermissionsFromRole(RoleModel $role, array $permissionKeys): void

    {

        ['map' => $map, 'missing' => $missing] = $this->resolvePermissionIdsByKeys($permissionKeys);



        if ($missing !== []) {

            throw new \InvalidArgumentException('Permission(s) not found: ' . implode(', ', $missing));

        }



        $role->permissions()->detach(array_values($map));
    }

    protected function resolvePermissionIdentifier(InputInterface $input): string
    {
        return trim((string) $input->getArgument('permission'));
    }

    protected function isValidPermissionKey(string $key): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_.*\-]*$/i', $key);
    }

    protected function resolvePermission(string $identifier): ?PermissionModel
    {
        if ($identifier === '') {
            return null;
        }

        if (ctype_digit($identifier)) {
            return PermissionModel::query()->where('permission_id', (int) $identifier)->first();
        }

        return PermissionModel::query()->where('permission_key', $identifier)->first();
    }

    protected function resolvePermissionInput(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $sectionTitle = 'Select permission',
    ): ?PermissionModel {
        $identifier = $this->resolvePermissionIdentifier($input);

        if ($identifier !== '') {
            return $this->resolvePermission($identifier);
        }

        if (!$input->isInteractive()) {
            throw new \RuntimeException('Permission is required in non-interactive mode.');
        }

        return $this->promptPermissionSelection($input, $output, $io, $sectionTitle);
    }

    protected function promptPermissionSelection(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $sectionTitle = 'Select permission',
    ): ?PermissionModel {
        $permissions = PermissionModel::query()->orderBy('permission_key')->get();

        if ($permissions->isEmpty()) {
            return null;
        }

        $io->section($sectionTitle);

        $table = new Table($output);
        $table->setHeaders(['ID', 'Key', 'Name', 'Description']);
        foreach ($permissions as $permission) {
            $table->addRow([
                $permission->permission_id,
                $permission->permission_key,
                $permission->name ?: '—',
                $permission->description ?: '—',
            ]);
        }
        $table->render();

        $candidates = [];
        foreach ($permissions as $permission) {
            $candidates[] = (string) $permission->permission_id;
            $candidates[] = (string) $permission->permission_key;
        }

        $question = new Question('Permission id or permission_key: ');
        $question->setAutocompleterValues(array_values(array_unique($candidates)));
        $question->setValidator(function ($answer) {
            $answer = trim((string) $answer);
            if ($answer === '') {
                throw new \RuntimeException('Permission is required.');
            }

            $permission = $this->resolvePermission($answer);
            if ($permission === null) {
                throw new \RuntimeException('Permission not found: ' . $answer);
            }

            return $permission;
        });

        $selected = $this->getHelper('question')->ask($input, $output, $question);

        return $selected instanceof PermissionModel ? $selected : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function permissionRow(PermissionModel $permission, bool $withRoles = false): array
    {
        $row = [
            'permission_id' => $permission->permission_id,
            'permission_key' => $permission->permission_key,
            'name' => $permission->name,
            'description' => $permission->description,
            'app' => $permission->app,
            'created_at' => $permission->created_at?->format('Y-m-d H:i:s'),
        ];

        if ($withRoles) {
            $row['roles'] = $permission->roles()->pluck('role_key')->all();
        }

        return $row;
    }
}
