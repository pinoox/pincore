<?php

use Pinoox\Model\UserModel;
use Pinoox\Support\Platform;
use Pinoox\Terminal\User\Concerns\ManagesCliUsers;
use Pinoox\Terminal\User\UserCreateCommand;
use Pinoox\Terminal\User\UserDeleteCommand;
use Pinoox\Terminal\User\UserListCommand;
use Pinoox\Terminal\User\UserPasswordCommand;
use Pinoox\Terminal\User\UserRoleCommand;
use Pinoox\Terminal\User\UserShowCommand;
use Pinoox\Terminal\User\UserStatusCommand;
use Pinoox\Terminal\User\UserUpdateCommand;

it('registers user CLI commands', function () {
    $application = cliApplication([
        new UserListCommand(),
        new UserCreateCommand(),
        new UserShowCommand(),
        new UserUpdateCommand(),
        new UserDeleteCommand(),
        new UserPasswordCommand(),
        new UserStatusCommand(),
        new UserRoleCommand(),
    ]);

    expect($application->has('user:list'))->toBeTrue()
        ->and($application->has('user:create'))->toBeTrue()
        ->and($application->has('user:show'))->toBeTrue()
        ->and($application->has('user:update'))->toBeTrue()
        ->and($application->has('user:delete'))->toBeTrue()
        ->and($application->has('user:password'))->toBeTrue()
        ->and($application->has('user:status'))->toBeTrue()
        ->and($application->has('user:role'))->toBeTrue();
});

it('requires optional package argument on user commands', function () {
    foreach ([
        new UserListCommand(),
        new UserCreateCommand(),
        new UserShowCommand(),
        new UserUpdateCommand(),
    ] as $command) {
        cliExpectOptionalPackageArgument($command);
    }
});

it('detects package-like CLI arguments', function () {
    $probe = cliTraitProbe([ManagesCliUsers::class]);

    expect(cliTraitInvoke($probe, 'looksLikePackageName', Platform::PACKAGE))->toBeTrue()
        ->and(cliTraitInvoke($probe, 'looksLikePackageName', 'com_test_cli_user'))->toBeTrue()
        ->and(cliTraitInvoke($probe, 'looksLikePackageName', 'admin'))->toBeFalse();
});

it('builds user row payload without database access', function () {
    $probe = cliTraitProbe([ManagesCliUsers::class]);

    $user = new UserModel();
    $user->user_id = 5;
    $user->username = 'cli_user';
    $user->email = 'cli@example.test';
    $user->fname = 'Cli';
    $user->lname = 'User';
    $user->status = UserModel::ACTIVE;
    $user->group_key = 'editor';
    $user->app = 'com_test_cli_user';

    $row = cliTraitInvoke($probe, 'userRow', $user);

    expect($row)->toMatchArray([
        'user_id' => 5,
        'username' => 'cli_user',
        'email' => 'cli@example.test',
        'status' => UserModel::ACTIVE,
        'group_key' => 'editor',
    ]);
});

it('parses user meta values and assignments', function () {
    $probe = cliTraitProbe([ManagesCliUsers::class]);

    expect(cliTraitInvoke($probe, 'parseUserMetaValue', 'true'))->toBeTrue()
        ->and(cliTraitInvoke($probe, 'parseUserMetaValue', '42'))->toBe(42)
        ->and(cliTraitInvoke($probe, 'parseUserMetaValue', '{"a":1}'))->toBe(['a' => 1]);

    $meta = cliTraitInvoke($probe, 'parseUserMetaAssignments', ['theme=dark', 'count=3']);

    expect($meta)->toBe(['theme' => 'dark', 'count' => 3]);
});

it('parses user --set assignments with metadata aliases', function () {
    $probe = cliTraitProbe([ManagesCliUsers::class]);

    $fields = cliTraitInvoke($probe, 'parseUserSetAssignments', [
        'first-name=Ali',
        'group=editor',
        'meta.theme=dark',
    ]);

    expect($fields['fname'])->toBe('Ali')
        ->and($fields['group_key'])->toBe('editor')
        ->and($fields['_metadata'])->toBe(['theme' => 'dark']);
});

it('normalizes user update field aliases', function () {
    $probe = cliTraitProbe([ManagesCliUsers::class]);

    expect(cliTraitInvoke($probe, 'normalizeUserUpdateField', 'last_name'))->toBe('lname')
        ->and(cliTraitInvoke($probe, 'normalizeUserUpdateField', 'group-key'))->toBe('group_key')
        ->and(cliTraitInvoke($probe, 'normalizeUserUpdateField', 'unknown'))->toBeNull();
});

it('lists supported user statuses', function () {
    $probe = cliTraitProbe([ManagesCliUsers::class]);

    expect(cliTraitInvoke($probe, 'userStatuses'))->toContain(
        UserModel::ACTIVE,
        UserModel::INACTIVE,
        UserModel::SUSPEND,
        UserModel::PENDING,
    );
});

it('rejects invalid user meta assignments', function () {
    $probe = cliTraitProbe([ManagesCliUsers::class]);

    expect(fn () => cliTraitInvoke($probe, 'parseUserMetaAssignments', ['invalid']))
        ->toThrow(InvalidArgumentException::class);
});
