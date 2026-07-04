<?php

use Pinoox\Component\Package\Routing\AppRouteMatcher;
use Pinoox\Terminal\App\AppRouterCommand;
use Symfony\Component\Console\Tester\CommandTester;

it('registers app:router bulk actions in help', function () {
    $command = new AppRouterCommand();
    $help = $command->getHelp();

    expect($help)->toContain('export')
        ->and($help)->toContain('sync')
        ->and($help)->toContain('edit');
});

it('exports routes as json', function () {
    $application = cliApplication([new AppRouterCommand()]);
    $tester = new CommandTester($application->find('app:router'));

    $status = $tester->execute([
        'action' => 'export',
    ], ['interactive' => false]);

    expect($status)->toBe(0);

    $decoded = json_decode(trim($tester->getDisplay()), true);
    expect($decoded)->toBeArray();
});

it('exports routes as php array', function () {
    $application = cliApplication([new AppRouterCommand()]);
    $tester = new CommandTester($application->find('app:router'));

    $status = $tester->execute([
        'action' => 'export',
        '--format' => 'php',
    ], ['interactive' => false]);

    expect($status)->toBe(0)
        ->and($tester->getDisplay())->toStartWith("<?php\n\nreturn [");
});

it('decodes route php files', function () {
    $command = new AppRouterCommand();
    $file = sys_get_temp_dir() . '/pinoox-router-php-' . uniqid('', true) . '.php';

    file_put_contents($file, <<<'PHP'
<?php

return [
    '/shop' => 'com_my_shop',
    '/panel' => 'com_panel',
];
PHP);

    try {
        $routes = cliTraitInvoke($command, 'loadRoutesFromFile', $file, 'php');
    } finally {
        @unlink($file);
    }

    expect($routes)->toBe([
        '/shop' => 'com_my_shop',
        '/panel' => 'com_panel',
    ]);
});

it('formats route php arrays', function () {
    $command = new AppRouterCommand();

    $php = cliTraitInvoke($command, 'formatRoutesPhp', [
        '/' => 'com_pinoox_welcome',
        '/developer' => 'com_pinoox_developer',
    ]);

    expect($php)->toBe(<<<'PHP'
<?php

return [
    '/' => 'com_pinoox_welcome',
    '/developer' => 'com_pinoox_developer',
];
PHP);
});

it('decodes route json objects', function () {
    $command = new AppRouterCommand();

    $routes = cliTraitInvoke($command, 'decodeRoutesJson', '{"shop":"com_my_shop","/panel":"com_panel"}');

    expect($routes)->toBe([
        '/shop' => 'com_my_shop',
        '/panel' => 'com_panel',
    ]);
});

it('rejects invalid route json', function () {
    $command = new AppRouterCommand();

    expect(fn () => cliTraitInvoke($command, 'decodeRoutesJson', 'not-json'))
        ->toThrow(InvalidArgumentException::class);
});

it('previews sync changes in dry-run mode', function () {
    $application = cliApplication([new AppRouterCommand()]);
    $tester = new CommandTester($application->find('app:router'));
    $file = sys_get_temp_dir() . '/pinoox-router-sync-' . uniqid('', true) . '.json';

    file_put_contents($file, json_encode([
        '/tmp-sync-test' => 'com_pinoox_welcome',
    ], JSON_THROW_ON_ERROR));

    try {
        $status = $tester->execute([
            'action' => 'sync',
            'route' => $file,
            '--dry-run' => true,
            '--force' => true,
        ], ['interactive' => false]);
    } finally {
        @unlink($file);
    }

    expect($status)->toBe(0)
        ->and($tester->getDisplay())->toContain('Sync preview')
        ->and($tester->getDisplay())->toContain('Dry run');
});

it('normalizes paths the same way as AppRouteMatcher', function () {
    expect(AppRouteMatcher::normalizeRoutes([
        'developer' => 'com_pinoox_developer',
        '/hub/' => 'com_pinoox_hub',
    ]))->toBe([
        '/developer' => 'com_pinoox_developer',
        '/hub' => 'com_pinoox_hub',
    ]);
});
