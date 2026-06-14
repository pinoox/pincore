<?php

use Pinoox\Terminal\Permission\PermissionCreateCommand;
use Pinoox\Terminal\Permission\PermissionDeleteCommand;
use Pinoox\Terminal\Permission\PermissionListCommand;
use Pinoox\Terminal\Permission\PermissionShowCommand;
use Pinoox\Terminal\Role\Concerns\ManagesCliRoles;
use Symfony\Component\Console\Input\InputArgument;

it('registers permission CLI commands', function () {
    $application = cliApplication([
        new PermissionListCommand(),
        new PermissionCreateCommand(),
        new PermissionShowCommand(),
        new PermissionDeleteCommand(),
    ]);

    expect($application->has('permission:list'))->toBeTrue()
        ->and($application->has('permission:create'))->toBeTrue()
        ->and($application->has('permission:show'))->toBeTrue()
        ->and($application->has('permission:delete'))->toBeTrue();
});

it('requires optional package argument on permission commands', function () {
    foreach ([
        new PermissionListCommand(),
        new PermissionCreateCommand(),
        new PermissionShowCommand(),
        new PermissionDeleteCommand(),
    ] as $command) {
        $argument = $command->getDefinition()->getArgument('package');

        expect($argument->isRequired())->toBeFalse()
            ->and($argument->getDefault())->toBeNull();
    }
});

it('validates permission keys in CLI trait', function () {
    $probe = cliTraitProbe([ManagesCliRoles::class]);

    expect(cliTraitInvoke($probe, 'isValidPermissionKey', 'blog.posts.view'))->toBeTrue()
        ->and(cliTraitInvoke($probe, 'isValidPermissionKey', 'manager.users.*'))->toBeTrue()
        ->and(cliTraitInvoke($probe, 'isValidPermissionKey', 'bad key'))->toBeFalse()
        ->and(cliTraitInvoke($probe, 'isValidPermissionKey', ''))->toBeFalse();
});

it('builds permission row payload without hitting database', function () {
    $probe = cliTraitProbe([ManagesCliRoles::class]);

    $permission = new Pinoox\Model\PermissionModel();
    $permission->permission_id = 7;
    $permission->permission_key = 'blog.posts.edit';
    $permission->name = 'Edit posts';
    $permission->description = 'Can edit blog posts';
    $permission->app = 'com_test_cli_perm';

    $row = cliTraitInvoke($probe, 'permissionRow', $permission);

    expect($row)->toMatchArray([
        'permission_id' => 7,
        'permission_key' => 'blog.posts.edit',
        'name' => 'Edit posts',
        'description' => 'Can edit blog posts',
        'app' => 'com_test_cli_perm',
    ])->and($row)->not->toHaveKey('roles');
});

it('requires permission argument on show and delete commands', function () {
    foreach ([
        new PermissionShowCommand(),
        new PermissionDeleteCommand(),
    ] as $command) {
        $argument = $command->getDefinition()->getArgument('permission');

        expect($argument)->toBeInstanceOf(InputArgument::class)
            ->and($argument->isRequired())->toBeFalse();
    }
});
