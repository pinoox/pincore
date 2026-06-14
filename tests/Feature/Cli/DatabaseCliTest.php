<?php

use Pinoox\Component\Database\DatabaseConnectionNormalizer;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Terminal\Database\DbCreateCommand;
use Pinoox\Terminal\Database\DbListCommand;
use Pinoox\Terminal\Database\DbPrefixCommand;
use Pinoox\Terminal\Database\DbShowCommand;
use Pinoox\Terminal\Database\DbTestCommand;
use Pinoox\Terminal\Database\DbUpdateCommand;
use Pinoox\Terminal\Database\Concerns\ManagesCliDatabase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    deleteTestApp('com_test_cli_db_show');
    AppEngine::__rebuild();
});

afterEach(function () {
    deleteTestApp('com_test_cli_db_show');
    AppEngine::__rebuild();
});

it('registers database CLI commands', function () {
    $application = cliApplication([
        new DbListCommand(),
        new DbShowCommand(),
        new DbTestCommand(),
        new DbCreateCommand(),
        new DbUpdateCommand(),
        new DbPrefixCommand(),
    ]);

    expect($application->has('db:list'))->toBeTrue()
        ->and($application->has('db:show'))->toBeTrue()
        ->and($application->has('db:test'))->toBeTrue()
        ->and($application->has('db:create'))->toBeTrue()
        ->and($application->has('db:update'))->toBeTrue()
        ->and($application->has('db:prefix'))->toBeTrue();
});

it('parses --set pairs with key aliases in database CLI trait', function () {
    $probe = cliTraitProbe([ManagesCliDatabase::class]);

    expect(cliTraitInvoke($probe, 'parseSetPair', 'db=shop'))->toBe(['database', 'shop'])
        ->and(cliTraitInvoke($probe, 'parseSetPair', 'user=root'))->toBe(['username', 'root'])
        ->and(cliTraitInvoke($probe, 'parseSetPair', 'pass=secret'))->toBe(['password', 'secret']);
});

it('rejects invalid --set pairs in database CLI trait', function () {
    $probe = cliTraitProbe([ManagesCliDatabase::class]);

    expect(fn () => cliTraitInvoke($probe, 'parseSetPair', 'invalid'))
        ->toThrow(InvalidArgumentException::class);
});

it('validates platform connection names in database CLI trait', function () {
    $probe = cliTraitProbe([ManagesCliDatabase::class]);

    cliTraitInvoke($probe, 'validateConnectionName', 'mysql');

    expect(fn () => cliTraitInvoke($probe, 'validateConnectionName', '1bad'))
        ->toThrow(InvalidArgumentException::class);
});

it('detects platform and app database targets', function () {
    writeTestApp('com_test_cli_db_show', []);
    AppEngine::__rebuild();

    $probe = cliTraitProbe([ManagesCliDatabase::class]);

    expect(cliTraitInvoke($probe, 'isPlatformTarget', 'platform'))->toBeTrue()
        ->and(cliTraitInvoke($probe, 'isAppTarget', 'com_test_cli_db_show'))->toBeTrue()
        ->and(cliTraitInvoke($probe, 'isAppTarget', 'platform'))->toBeFalse();
});

it('resolves platform target to a concrete connection name in non-interactive mode', function () {
    $probe = cliTraitProbe([ManagesCliDatabase::class]);
    $application = cliApplication([$probe]);
    $command = $application->find('cli:trait-probe');

    $input = new ArrayInput([], $command->getDefinition());
    $input->setInteractive(false);
    $output = new BufferedOutput();

    $resolved = cliTraitInvoke(
        $command,
        'resolvePlatformConnectionName',
        $input,
        $output,
        new Symfony\Component\Console\Style\SymfonyStyle($input, $output),
        argument: null,
    );

    expect($resolved)->not->toBe('platform')
        ->and($resolved)->toBeString()
        ->and($resolved)->not->toBe('');
});

it('tests sqlite connectivity via db:test ad-hoc options', function () {
    if (!extension_loaded('pdo_sqlite')) {
        expect(true)->toBeTrue();

        return;
    }

    $application = cliApplication([new DbTestCommand()]);
    $tester = new CommandTester($application->find('db:test'));

    $status = $tester->execute([
        '--driver' => 'sqlite',
        '--database' => ':memory:',
    ], ['interactive' => false]);

    expect($status)->toBe(0)
        ->and($tester->getDisplay())->toContain('Connection successful');
});

it('shows app database details as json via db:show', function () {
    writeTestApp('com_test_cli_db_show', [
        'database' => [
            'use' => 'platform',
            'prefix' => 'show_',
        ],
    ]);
    AppEngine::__rebuild();

    $application = cliApplication([new DbShowCommand()]);
    $tester = new CommandTester($application->find('db:show'));

    $status = $tester->execute([
        'target' => 'com_test_cli_db_show',
        '--json' => true,
    ], ['interactive' => false]);

    expect($status)->toBe(0);

    $payload = json_decode($tester->getDisplay(), true);

    expect($payload)->toBeArray()
        ->and($payload['package'] ?? null)->toBe('com_test_cli_db_show')
        ->and($payload['mode'] ?? null)->toBe('platform + prefix');
});

it('reports sqlite probe success through DatabaseConnectionNormalizer', function () {
    if (!extension_loaded('pdo_sqlite')) {
        expect(true)->toBeTrue();

        return;
    }

    expect(DatabaseConnectionNormalizer::test([
        'driver' => 'sqlite',
        'database' => ':memory:',
    ]))->toBeTrue();
});
