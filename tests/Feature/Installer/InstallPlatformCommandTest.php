<?php

use App\com_pinoox_installer\Component\InstallPlatformConfig;
use App\com_pinoox_installer\Component\InstallPlatformException;
use App\com_pinoox_installer\Terminal\InstallPlatformCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

$installPlatformFiles = [];

function installPlatformTempFile(): string
{
    return str_replace('\\', '/', sys_get_temp_dir()) . '/pinoox-install-platform-' . uniqid('', true) . '.php';
}

function installPlatformValidPayload(): array
{
    return [
        'lang' => 'fa',
        'db' => [
            'connection' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'pinoox',
            'username' => 'root',
            'password' => 'secret',
            'prefix' => 'pinx_',
            'timezone' => '+03:30',
        ],
        'user' => [
            'fname' => 'Admin',
            'lname' => 'Pinoox',
            'email' => 'admin@example.com',
            'username' => 'admin',
            'password' => 'secret1',
        ],
    ];
}

afterEach(function () use (&$installPlatformFiles) {
    foreach ($installPlatformFiles as $path) {
        if (is_string($path) && is_file($path)) {
            @unlink($path);
        }
    }

    $installPlatformFiles = [];
});

it('registers the install-platform command', function () {
    $command = new InstallPlatformCommand();

    expect($command->getName())->toBe('install-platform')
        ->and($command->getAliases())->toContain('platform:install')
        ->and($command->getDefinition()->hasArgument('action'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('file'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('force'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('remove'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('delete'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('dry-run'))->toBeTrue();
});

it('writes and reloads a complete install-platform stub', function () use (&$installPlatformFiles) {
    $path = $installPlatformFiles[] = installPlatformTempFile();
    $payload = installPlatformValidPayload();

    InstallPlatformConfig::writeStub($path, true, $payload);

    expect(is_file($path))->toBeTrue();

    $loaded = InstallPlatformConfig::load($path);

    expect($loaded['lang'])->toBe('fa')
        ->and($loaded['db']['database'])->toBe('pinoox')
        ->and($loaded['user']['username'])->toBe('admin')
        ->and($loaded['user']['password'])->toBe('secret1');
});

it('refuses to overwrite an existing stub without force', function () use (&$installPlatformFiles) {
    $path = $installPlatformFiles[] = installPlatformTempFile();
    InstallPlatformConfig::writeStub($path, false, installPlatformValidPayload());

    expect(fn () => InstallPlatformConfig::writeStub($path, false, installPlatformValidPayload()))
        ->toThrow(InstallPlatformException::class);
});

it('rejects an incomplete payload', function () {
    expect(fn () => InstallPlatformConfig::validate([
        'lang' => 'en',
        'db' => [
            'host' => '127.0.0.1',
        ],
        'user' => [
            'username' => 'ad',
            'password' => '1',
        ],
    ]))->toThrow(InstallPlatformException::class);
});

it('inits a config file through the CLI', function () use (&$installPlatformFiles) {
    $path = $installPlatformFiles[] = installPlatformTempFile();
    $application = cliApplication([new InstallPlatformCommand()]);
    $tester = new CommandTester($application->find('install-platform'));

    $status = $tester->execute([
        'action' => 'init',
        '--file' => $path,
    ], ['interactive' => false]);

    expect($status)->toBe(Command::SUCCESS)
        ->and(is_file($path))->toBeTrue()
        ->and($tester->getDisplay())->toContain($path)
        ->and(file_get_contents($path))->toContain("'db'")
        ->and(file_get_contents($path))->toContain("'user'");
});

it('dry-runs a valid config without installing', function () use (&$installPlatformFiles) {
    $path = $installPlatformFiles[] = installPlatformTempFile();
    InstallPlatformConfig::writeStub($path, true, installPlatformValidPayload());

    $application = cliApplication([new InstallPlatformCommand()]);
    $tester = new CommandTester($application->find('install-platform'));

    $status = $tester->execute([
        'action' => 'run',
        '--file' => $path,
        '--dry-run' => true,
    ], ['interactive' => false]);

    expect($status)->toBe(Command::SUCCESS)
        ->and($tester->getDisplay())->toContain('dry-run')
        ->and($tester->getDisplay())->toContain('Config is valid');
});

it('fails run when the config file is missing', function () use (&$installPlatformFiles) {
    $path = $installPlatformFiles[] = installPlatformTempFile();

    $application = cliApplication([new InstallPlatformCommand()]);
    $tester = new CommandTester($application->find('install-platform'));

    $status = $tester->execute([
        'action' => 'run',
        '--file' => $path,
    ], ['interactive' => false]);

    expect($status)->toBe(Command::FAILURE)
        ->and($tester->getDisplay())->toContain('Config not found');
});

it('deletes a config file via InstallPlatformConfig::remove', function () use (&$installPlatformFiles) {
    $path = $installPlatformFiles[] = installPlatformTempFile();
    InstallPlatformConfig::writeStub($path, true, installPlatformValidPayload());

    expect(is_file($path))->toBeTrue()
        ->and(InstallPlatformConfig::remove($path))->toBeTrue()
        ->and(is_file($path))->toBeFalse()
        ->and(InstallPlatformConfig::remove($path))->toBeTrue();
});

it('does not delete the config on dry-run even with -r', function () use (&$installPlatformFiles) {
    $path = $installPlatformFiles[] = installPlatformTempFile();
    InstallPlatformConfig::writeStub($path, true, installPlatformValidPayload());

    $application = cliApplication([new InstallPlatformCommand()]);
    $tester = new CommandTester($application->find('install-platform'));

    $status = $tester->execute([
        'action' => 'run',
        '--file' => $path,
        '--dry-run' => true,
        '--remove' => true,
    ], ['interactive' => false]);

    expect($status)->toBe(Command::SUCCESS)
        ->and(is_file($path))->toBeTrue();
});

it('rejects an unknown action', function () {
    $application = cliApplication([new InstallPlatformCommand()]);
    $tester = new CommandTester($application->find('install-platform'));

    $status = $tester->execute([
        'action' => 'explode',
    ], ['interactive' => false]);

    expect($status)->toBe(Command::INVALID);
});
