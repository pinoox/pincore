<?php

use Pinoox\Terminal\Database\QueryCommand;
use Symfony\Component\Console\Tester\CommandTester;

it('registers query command with sql argument', function () {
    $application = cliApplication([new QueryCommand()]);

    expect($application->has('query'))->toBeTrue();

    cliExpectRequiredArgument(new QueryCommand(), 'sql');
});

it('outputs sql in dry-run mode without executing', function () {
    $application = cliApplication([new QueryCommand()]);
    $tester = new CommandTester($application->find('query'));

    $status = $tester->execute([
        'sql' => 'SELECT 1',
        '--dry-run' => true,
    ], ['interactive' => false]);

    expect($status)->toBe(0)
        ->and($tester->getDisplay())->toContain('SELECT 1');
});

it('classifies sql statement types', function () {
    $command = new QueryCommand();

    expect(cliTraitInvoke($command, 'getQueryType', 'select * from users'))->toBe('SELECT')
        ->and(cliTraitInvoke($command, 'getQueryType', 'UPDATE users SET status = 1'))->toBe('UPDATE')
        ->and(cliTraitInvoke($command, 'getQueryType', 'DROP TABLE tmp'))->toBe('DROP');
});

it('detects destructive sql statements', function () {
    $command = new QueryCommand();

    expect(cliTraitInvoke($command, 'isDestructiveQuery', 'DELETE FROM users'))->toBeTrue()
        ->and(cliTraitInvoke($command, 'isDestructiveQuery', 'SELECT * FROM users'))->toBeFalse();
});

it('requires confirmation for unbounded delete and update statements', function () {
    $command = new QueryCommand();

    expect(cliTraitInvoke($command, 'shouldConfirm', 'DELETE FROM users'))->toBeTrue()
        ->and(cliTraitInvoke($command, 'shouldConfirm', 'DELETE FROM users WHERE id = 1'))->toBeFalse()
        ->and(cliTraitInvoke($command, 'shouldConfirm', 'DROP TABLE users'))->toBeTrue();
});
