<?php

use Pinoox\Model\RoleModel;
use Pinoox\Support\Platform;
use Pinoox\Terminal\Role\Concerns\ManagesCliRoles;
use Pinoox\Terminal\Role\RoleCreateCommand;
use Pinoox\Terminal\Role\RoleDeleteCommand;
use Pinoox\Terminal\Role\RoleListCommand;
use Pinoox\Terminal\Role\RolePermissionCommand;
use Pinoox\Terminal\Role\RoleShowCommand;
use Pinoox\Terminal\Role\RoleUpdateCommand;

it('registers role CLI commands', function () {
    $application = cliApplication([
        new RoleListCommand(),
        new RoleCreateCommand(),
        new RoleShowCommand(),
        new RoleUpdateCommand(),
        new RoleDeleteCommand(),
        new RolePermissionCommand(),
    ]);

    expect($application->has('role:list'))->toBeTrue()
        ->and($application->has('role:create'))->toBeTrue()
        ->and($application->has('role:show'))->toBeTrue()
        ->and($application->has('role:update'))->toBeTrue()
        ->and($application->has('role:delete'))->toBeTrue()
        ->and($application->has('role:permission'))->toBeTrue();
});

it('requires optional package argument on role commands', function () {
    foreach ([
        new RoleListCommand(),
        new RoleCreateCommand(),
        new RoleShowCommand(),
        new RoleUpdateCommand(),
    ] as $command) {
        cliExpectOptionalPackageArgument($command);
    }
});

it('detects access package names in role CLI trait', function () {
    $probe = cliTraitProbe([ManagesCliRoles::class]);

    expect(cliTraitInvoke($probe, 'looksLikeAccessPackage', Platform::PACKAGE))->toBeTrue()
        ->and(cliTraitInvoke($probe, 'looksLikeAccessPackage', 'com_test_cli_role'))->toBeTrue()
        ->and(cliTraitInvoke($probe, 'looksLikeAccessPackage', 'io_yoosefap_ai'))->toBeTrue()
        ->and(cliTraitInvoke($probe, 'looksLikeAccessPackage', 'editor'))->toBeFalse();
});

it('builds role row payload without database access', function () {
    $probe = cliTraitProbe([ManagesCliRoles::class]);

    $role = new RoleModel();
    $role->role_id = 3;
    $role->role_key = 'editor';
    $role->name = 'Editor';
    $role->description = 'Can edit content';
    $role->app = 'com_test_cli_role';

    $row = cliTraitInvoke($probe, 'roleRow', $role);

    expect($row)->toMatchArray([
        'role_id' => 3,
        'role_key' => 'editor',
        'name' => 'Editor',
        'description' => 'Can edit content',
    ]);
});

it('returns empty role id map for empty key list', function () {
    $probe = cliTraitProbe([ManagesCliRoles::class]);

    expect(cliTraitInvoke($probe, 'resolveRoleIdsByKeys', []))
        ->toBe(['map' => [], 'missing' => []]);
});

it('returns empty permission id map for empty key list', function () {
    $probe = cliTraitProbe([ManagesCliRoles::class]);

    expect(cliTraitInvoke($probe, 'resolvePermissionIdsByKeys', []))
        ->toBe(['map' => [], 'missing' => []]);
});

it('requires optional role argument on show and delete commands', function () {
    foreach ([
        new RoleShowCommand(),
        new RoleDeleteCommand(),
    ] as $command) {
        cliExpectOptionalPackageArgument($command);

        $roleArg = $command->getDefinition()->getArgument('role');
        expect($roleArg->isRequired())->toBeFalse();
    }
});
