<?php

use Symfony\Component\Console\Command\Command;

it('discovers all core Terminal command classes', function () {
    $classes = cliCoreCommandClasses();

    expect($classes)->not->toBeEmpty()
        ->and($classes)->toContain(Pinoox\Terminal\Database\DbListCommand::class)
        ->and($classes)->toContain(Pinoox\Terminal\User\UserListCommand::class)
        ->and($classes)->toContain(Pinoox\Terminal\Pincore\VersionCommand::class);
});

it('instantiates and registers every core CLI command', function (string $className) {
    $command = cliInstantiateCommand($className);

    expect($command)->toBeInstanceOf(Command::class)
        ->and($command->getName())->not->toBeEmpty();

    $application = cliApplication([$command]);

    foreach (cliCommandNames($command) as $name) {
        expect($application->has($name))->toBeTrue();
    }
})->with(fn () => array_map(static fn (string $class) => [$class], cliCoreCommandClasses()));

it('registers all core CLI commands in one application without name collisions', function () {
    $application = cliApplication(array_map(
        static fn (string $class) => cliInstantiateCommand($class),
        cliCoreCommandClasses(),
    ));

    $registered = [];

    foreach (cliCoreCommandClasses() as $className) {
        $command = cliInstantiateCommand($className);
        $registered[] = $command->getName();
    }

    expect(count($registered))->toBe(count(array_unique($registered)));
});
