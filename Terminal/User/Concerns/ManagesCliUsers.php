<?php

namespace Pinoox\Terminal\User\Concerns;

use Pinoox\Component\Transport\TransportRuntime;
use Pinoox\Model\RoleModel;
use Pinoox\Model\UserModel;
use Pinoox\Portal\Auth;
use Pinoox\Portal\Database\DB;
use Pinoox\Support\Platform;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

trait ManagesCliUsers
{
    use SelectsPackage;

    protected function resolveUserPackage(InputInterface $input, OutputInterface $output, SymfonyStyle $io, string $sectionTitle = 'Manage users for'): string
    {
        return $this->resolvePackageRequired($input, $output, $io, [
            'sectionTitle' => $sectionTitle,
        ]);
    }

    protected function prepareUserScope(string $package): void
    {
        DB::ensureRegistered();
        UserModel::clearBootedModels();
        RoleModel::clearBootedModels();

        TransportRuntime::use($package);
    }

    protected function resolveUser(string $identifier): ?UserModel
    {
        if ($identifier === '') {
            return null;
        }

        if (ctype_digit($identifier)) {
            return Auth::find((int) $identifier);
        }

        return Auth::findByLogin($identifier, false);
    }

    protected function resolveUserInput(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $sectionTitle = 'Select user',
    ): ?UserModel {
        $identifier = $this->resolveUserIdentifier($input);

        if ($identifier !== '') {
            return $this->resolveUser($identifier);
        }

        if (!$input->isInteractive()) {
            throw new \RuntimeException('User is required in non-interactive mode.');
        }

        return $this->promptUserSelection($input, $output, $io, $sectionTitle);
    }

    protected function promptUserSelection(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $sectionTitle = 'Select user',
    ): ?UserModel {
        $users = UserModel::query()->orderBy('user_id')->get();

        if ($users->isEmpty()) {
            return null;
        }

        $io->section($sectionTitle);

        $table = new Table($output);
        $table->setHeaders(['ID', 'Username', 'Email', 'Name', 'Status']);
        foreach ($users as $user) {
            $table->addRow([
                $user->user_id,
                $user->username,
                $user->email ?: '—',
                trim($user->full_name) ?: '—',
                $user->status,
            ]);
        }
        $table->render();

        $candidates = [];
        foreach ($users as $user) {
            $candidates[] = (string) $user->user_id;
            $candidates[] = (string) $user->username;
            if (is_string($user->email) && $user->email !== '') {
                $candidates[] = $user->email;
            }
        }

        $question = new Question('User id, username, or email: ');
        $question->setAutocompleterValues(array_values(array_unique($candidates)));
        $question->setValidator(function ($answer) {
            $answer = trim((string) $answer);
            if ($answer === '') {
                throw new \RuntimeException('User is required.');
            }

            $user = $this->resolveUser($answer);
            if ($user === null) {
                throw new \RuntimeException('User not found: ' . $answer);
            }

            return $user;
        });

        $selected = $this->getHelper('question')->ask($input, $output, $question);

        return $selected instanceof UserModel ? $selected : null;
    }

    /**
     * @return list<string>
     */
    protected function userStatuses(): array
    {
        return [
            UserModel::ACTIVE,
            UserModel::INACTIVE,
            UserModel::SUSPEND,
            UserModel::PENDING,
        ];
    }

    protected function attachRole(UserModel $user, string $roleKey): bool
    {
        $role = RoleModel::query()
            ->where('role_key', $roleKey)
            ->first();

        if ($role === null) {
            return false;
        }

        $user->roles()->syncWithoutDetaching([$role->role_id]);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function userRow(UserModel $user, bool $includeRoles = false): array
    {
        $row = [
            'user_id' => $user->user_id,
            'username' => $user->username,
            'email' => $user->email,
            'full_name' => $user->full_name,
            'status' => $user->status,
            'group_key' => $user->group_key,
            'mobile' => $user->mobile,
            'app' => $user->app,
            'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
        ];

        if ($includeRoles) {
            $row['roles'] = $user->roles()->pluck('role_key')->all();
        }

        return $row;
    }

    protected function looksLikePackageName(string $value): bool
    {
        return $value === Platform::PACKAGE || str_starts_with($value, 'com_');
    }

    protected function resolveUserIdentifier(InputInterface $input): string
    {
        return (string) $input->getArgument('user');
    }

    protected function resolveUserPackageInput(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $sectionTitle = 'Manage users for',
    ): string {
        $explicitPackage = (string) ($input->getArgument('package') ?? '');

        if ($explicitPackage !== '' && $this->looksLikePackageName($explicitPackage)) {
            return $explicitPackage;
        }

        return $this->resolveUserPackage($input, $output, $io, $sectionTitle);
    }

    /**
     * @return list<string>
     */
    protected function userUpdateFields(): array
    {
        return [
            'username',
            'email',
            'fname',
            'lname',
            'mobile',
            'group_key',
            'status',
            'personal_id',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function userUpdateFieldAliases(): array
    {
        return [
            'username' => 'username',
            'email' => 'email',
            'fname' => 'fname',
            'first-name' => 'fname',
            'first_name' => 'fname',
            'firstname' => 'fname',
            'lname' => 'lname',
            'last-name' => 'lname',
            'last_name' => 'lname',
            'lastname' => 'lname',
            'mobile' => 'mobile',
            'phone' => 'mobile',
            'group' => 'group_key',
            'group-key' => 'group_key',
            'group_key' => 'group_key',
            'status' => 'status',
            'personal-id' => 'personal_id',
            'personal_id' => 'personal_id',
            'personalid' => 'personal_id',
        ];
    }

    protected function normalizeUserUpdateField(string $name): ?string
    {
        $key = strtolower(trim(str_replace('_', '-', $name)));

        return $this->userUpdateFieldAliases()[$key] ?? null;
    }

    protected function userUpdateFieldLabel(string $field): string
    {
        return match ($field) {
            'fname' => 'First name',
            'lname' => 'Last name',
            'group_key' => 'Group key',
            'personal_id' => 'Personal ID',
            default => ucfirst(str_replace('_', ' ', $field)),
        };
    }

    /**
     * @return array<string, string>
     */
    protected function userUpdateOptionMap(): array
    {
        return [
            'username' => 'username',
            'email' => 'email',
            'fname' => 'fname',
            'lname' => 'lname',
            'mobile' => 'mobile',
            'group-key' => 'group_key',
            'status' => 'status',
            'personal-id' => 'personal_id',
        ];
    }

    /**
     * @param list<string|null> $setAssignments
     * @return array<string, mixed>
     */
    protected function parseUserSetAssignments(array $setAssignments): array
    {
        $fields = [];

        foreach ($setAssignments as $assignment) {
            if (!is_string($assignment) || $assignment === '') {
                continue;
            }

            if (!str_contains($assignment, '=')) {
                throw new \InvalidArgumentException(
                    'Invalid --set value "' . $assignment . '". Use field=value.',
                );
            }

            [$rawField, $value] = explode('=', $assignment, 2);
            $field = $this->normalizeUserUpdateField($rawField);

            if ($field === null) {
                throw new \InvalidArgumentException(
                    'Unknown field "' . $rawField . '". Allowed: '
                    . implode(', ', $this->userUpdateFields()),
                );
            }

            $fields[$field] = $value;
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    protected function collectUserUpdateFields(InputInterface $input): array
    {
        $fields = $this->parseUserSetAssignments($input->getOption('set') ?? []);

        foreach ($this->userUpdateOptionMap() as $option => $field) {
            if (!$input->hasOption($option)) {
                continue;
            }

            $value = $input->getOption($option);
            if ($value === null) {
                continue;
            }

            $fields[$field] = $value;
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    protected function promptUserFieldUpdates(SymfonyStyle $io, UserModel $user): array
    {
        $fields = [];

        $io->section(sprintf('Update user #%d (%s)', $user->user_id, $user->username));
        $io->writeln('Press Enter to keep the current value.');

        foreach ($this->userUpdateFields() as $field) {
            $current = (string) ($user->{$field} ?? '');
            $label = $this->userUpdateFieldLabel($field);

            if ($field === 'status') {
                $value = (string) $io->choice(
                    $label,
                    $this->userStatuses(),
                    in_array($current, $this->userStatuses(), true) ? $current : UserModel::ACTIVE,
                );

                if ($value !== $current) {
                    $fields[$field] = $value;
                }

                continue;
            }

            $answer = $io->ask($label, $current);
            if (!is_string($answer) || $answer === $current) {
                continue;
            }

            $fields[$field] = $answer;
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function applyUserFieldUpdates(UserModel $user, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        if (isset($fields['status']) && !in_array($fields['status'], $this->userStatuses(), true)) {
            throw new \InvalidArgumentException(
                'Invalid status "' . $fields['status'] . '". Use: ' . implode(', ', $this->userStatuses()),
            );
        }

        $user->fill($fields);
        $user->save();
    }

    /**
     * @param array<string, mixed> $fields
     * @return list<string>
     */
    protected function describeUserFieldUpdates(array $fields): array
    {
        $lines = [];

        foreach ($fields as $field => $value) {
            $lines[] = $this->userUpdateFieldLabel($field) . ': ' . (string) $value;
        }

        return $lines;
    }
}
