<?php

use Pinoox\Portal\App\AppEngine;
use Pinoox\Terminal\Concerns\SelectsPackage;

beforeEach(function () {
    deleteTestApp('com_test_cli_select');
    AppEngine::__rebuild();
});

afterEach(function () {
    deleteTestApp('com_test_cli_select');
    AppEngine::__rebuild();
});

it('normalizes package input by stripping BOM and invalid prefixes', function () {
    $probe = cliTraitProbe([SelectsPackage::class]);

    expect(cliTraitInvoke($probe, 'normalizePackageInput', 'com_test_cli_select'))
        ->toBe('com_test_cli_select')
        ->and(cliTraitInvoke($probe, 'normalizePackageInput', "\xEF\xBB\xBFcom_test_cli_select"))
        ->toBe('com_test_cli_select');
});

it('resolves package answers by key or numeric index', function () {
    $probe = cliTraitProbe([SelectsPackage::class]);
    $packages = [
        'platform' => 'Core platform',
        'com_test_cli_select' => 'Test app',
    ];

    expect(cliTraitInvoke($probe, 'resolvePackageAnswer', 'platform', $packages))->toBe('platform')
        ->and(cliTraitInvoke($probe, 'resolvePackageAnswer', '1', $packages))->toBe('com_test_cli_select')
        ->and(cliTraitInvoke($probe, 'resolvePackageAnswer', 'missing', $packages))->toBeNull();
});

it('checks package existence for platform and fake apps', function () {
    writeTestApp('com_test_cli_select', ['name' => 'Select test']);
    AppEngine::__rebuild();

    $probe = cliTraitProbe([SelectsPackage::class]);

    expect(cliTraitInvoke($probe, 'packageExists', 'platform', false, false, false))->toBeTrue()
        ->and(cliTraitInvoke($probe, 'packageExists', 'com_test_cli_select', false, false, false))->toBeTrue()
        ->and(cliTraitInvoke($probe, 'packageExists', 'com_missing_app', false, false, false))->toBeFalse()
        ->and(cliTraitInvoke($probe, 'packageExists', 'all', false, true, false))->toBeTrue()
        ->and(cliTraitInvoke($probe, 'packageExists', 'platform', false, false, true))->toBeFalse();
});

it('builds package choice rows for interactive selection', function () {
    $probe = cliTraitProbe([SelectsPackage::class]);
    $rows = cliTraitInvoke($probe, 'packageRows', [
        'platform' => 'Core platform',
        'com_test_cli_select' => 'Test app',
    ]);

    expect($rows)->toHaveCount(2)
        ->and($rows[0][1])->toBe('platform')
        ->and($rows[1][1])->toBe('com_test_cli_select');
});

it('returns default package in non-interactive required selection', function () {
    $probe = cliTraitProbe([SelectsPackage::class]);
    $application = cliApplication([$probe]);
    $command = $application->find('cli:trait-probe');

    $input = new Symfony\Component\Console\Input\ArrayInput([], $command->getDefinition());
    $input->setInteractive(false);
    $output = new Symfony\Component\Console\Output\BufferedOutput();

    $package = cliTraitInvoke(
        $command,
        'resolvePackageRequired',
        $input,
        $output,
        new Symfony\Component\Console\Style\SymfonyStyle($input, $output),
        ['default' => 'platform'],
    );

    expect($package)->toBe('platform');
});

it('includes package selection help text', function () {
    $probe = cliTraitProbe([SelectsPackage::class]);

    expect(cliTraitInvoke($probe, 'packageArgumentHelp', false, false))
        ->toContain('com_my_shop')
        ->and(cliTraitInvoke($probe, 'packageArgumentHelp', true, false))
        ->toContain('all');
});
