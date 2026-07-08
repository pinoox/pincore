<?php

use Pinoox\Component\Doctor\CheckStatus;
use Pinoox\Component\Doctor\DoctorReport;
use Pinoox\Component\Doctor\DoctorRunner;
use Pinoox\Terminal\Doctor\DoctorCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

it('registers the doctor command', function () {
    $command = new DoctorCommand();

    expect($command->getName())->toBe('doctor')
        ->and($command->getDefinition()->hasOption('json'))->toBeTrue()
        ->and($command->getDefinition()->hasOption('skip-db'))->toBeTrue();
});

it('builds a doctor report for platform scope', function () {
    $root = rtrim(str_replace('\\', '/', (string) PINOOX_BASE_PATH), '/');
    $report = (new DoctorRunner(skipDatabase: true, skipFrontend: true))
        ->runProject($root, 'platform');

    expect($report)->toBeInstanceOf(DoctorReport::class)
        ->and($report->all())->not->toBeEmpty()
        ->and($report->toArray()['summary'])->toHaveKeys(['pass', 'warn', 'fail']);
});

it('outputs json from doctor command', function () {
    $command = new DoctorCommand();
    $tester = new CommandTester($command);
    $exitCode = $tester->execute([
        'package' => 'platform',
        '--json' => true,
        '--skip-db' => true,
        '--skip-frontend' => true,
    ]);

    $payload = json_decode($tester->getDisplay(), true);

    expect($payload)->toBeArray()
        ->and($payload)->toHaveKeys(['healthy', 'score', 'summary', 'checks', 'fixes'])
        ->and($exitCode)->toBeIn([Command::SUCCESS, Command::FAILURE]);
});

it('scores pass and warn checks', function () {
    $report = new DoctorReport();
    $report->add(new \Pinoox\Component\Doctor\CheckItem(
        group: 'Test',
        id: 'pass',
        label: 'Pass',
        status: CheckStatus::Pass,
    ));
    $report->add(new \Pinoox\Component\Doctor\CheckItem(
        group: 'Test',
        id: 'warn',
        label: 'Warn',
        status: CheckStatus::Warn,
    ));

    expect($report->score())->toBe(75)
        ->and($report->isHealthy())->toBeTrue()
        ->and($report->failCount())->toBe(0)
        ->and($report->warnCount())->toBe(1);
});
