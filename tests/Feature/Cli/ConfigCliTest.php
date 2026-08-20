<?php

use Pinoox\Component\Template\Theme\ThemeManifest;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\Config;
use Pinoox\Terminal\Config\Concerns\ManagesCliConfig;
use Pinoox\Terminal\Config\ConfigGetCommand;
use Pinoox\Terminal\Config\ConfigRemoveCommand;
use Pinoox\Terminal\Config\ConfigSetCommand;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    deleteTestApp('com_test_cli_config');
    AppEngine::__rebuild();
});

afterEach(function () {
    deleteTestApp('com_test_cli_config');
    AppEngine::__rebuild();
});

it('registers config CLI commands', function () {
    $application = cliApplication([
        new ConfigSetCommand(),
        new ConfigGetCommand(),
        new ConfigRemoveCommand(),
    ]);

    expect($application->has('config:set'))->toBeTrue()
        ->and($application->has('config:get'))->toBeTrue()
        ->and($application->has('config:show'))->toBeTrue()
        ->and($application->has('config:remove'))->toBeTrue();
});

it('parses CLI config values in the config trait', function () {
    $probe = cliTraitProbe([ManagesCliConfig::class]);

    expect(cliTraitInvoke($probe, 'parseCliValue', 'true'))->toBeTrue()
        ->and(cliTraitInvoke($probe, 'parseCliValue', 'false'))->toBeFalse()
        ->and(cliTraitInvoke($probe, 'parseCliValue', 'null'))->toBeNull()
        ->and(cliTraitInvoke($probe, 'parseCliValue', '12'))->toBe(12)
        ->and(cliTraitInvoke($probe, 'parseCliValue', '{"a":1}'))->toBe(['a' => 1])
        ->and(cliTraitInvoke($probe, 'parseConfigPair', 'theme=spark'))->toBe(['theme', 'spark']);
});

it('sets and reads app.php keys via config:set and config:get', function () {
    writeTestApp('com_test_cli_config', [
        'theme' => 'default',
        'enable' => true,
    ]);

    $application = cliApplication([
        new ConfigSetCommand(),
        new ConfigGetCommand(),
    ]);

    $set = new CommandTester($application->find('config:set'));
    $status = $set->execute([
        'package' => 'com_test_cli_config',
        'key' => 'theme',
        'value' => 'spark',
        '--set' => ['enable=false'],
    ], ['interactive' => false]);

    expect($status)->toBe(0)
        ->and($set->getDisplay())->toContain('theme=spark');

    AppEngine::__rebuild();

    expect(AppEngine::config('com_test_cli_config')->get('theme'))->toBe('spark')
        ->and(AppEngine::config('com_test_cli_config')->get('enable'))->toBeFalse();

    $get = new CommandTester($application->find('config:get'));
    $status = $get->execute([
        'package' => 'com_test_cli_config',
        'key' => 'theme',
        '--json' => true,
    ], ['interactive' => false]);

    expect($status)->toBe(0);

    $payload = json_decode($get->getDisplay(), true);
    expect($payload)->toBeArray()
        ->and($payload['value'] ?? null)->toBe('spark')
        ->and($payload['file'] ?? null)->toBe('app.php');
});

it('sets theme.php keys via config:set', function () {
    $package = 'com_test_cli_config';
    writeTestApp($package, ['theme' => 'spark']);
    $themeDir = AppEngine::path($package, 'theme/spark');

    if (!is_dir($themeDir)) {
        mkdir($themeDir, 0777, true);
    }

    file_put_contents($themeDir . '/theme.php', "<?php\n\nreturn [\n    'name' => 'spark',\n    'package' => '{$package}',\n    'developer' => 'old',\n];\n");

    $application = cliApplication([new ConfigSetCommand(), new ConfigGetCommand()]);
    $set = new CommandTester($application->find('config:set'));

    $status = $set->execute([
        'package' => $package,
        'key' => 'developer',
        'value' => 'pinoox',
        '--file' => 'theme.php',
        '--theme' => 'spark',
    ], ['interactive' => false]);

    expect($status)->toBe(0);

    $manifest = ThemeManifest::load($package, 'spark');
    expect($manifest)->not->toBeNull()
        ->and($manifest->developer())->toBe('pinoox');

    $get = new CommandTester($application->find('config:get'));
    $status = $get->execute([
        'package' => $package,
        'key' => 'developer',
        '--file' => 'theme.php',
        '--theme' => 'spark',
        '--json' => true,
    ], ['interactive' => false]);

    expect($status)->toBe(0);
    $payload = json_decode($get->getDisplay(), true);
    expect($payload['value'] ?? null)->toBe('pinoox');
});

it('sets app config files via config:set --file', function () {
    $package = 'com_test_cli_config';
    writeTestApp($package, []);
    $configDir = AppEngine::path($package, 'config');

    if (!is_dir($configDir)) {
        mkdir($configDir, 0777, true);
    }

    file_put_contents($configDir . '/options.config.php', "<?php\n\nreturn ['enabled' => false];\n");

    $application = cliApplication([new ConfigSetCommand()]);
    $tester = new CommandTester($application->find('config:set'));

    $status = $tester->execute([
        'package' => $package,
        'key' => 'enabled',
        'value' => 'true',
        '--file' => 'options',
    ], ['interactive' => false]);

    expect($status)->toBe(0);

    expect(Config::name($package . ':options')->get('enabled'))->toBeTrue();
});

it('previews config:set changes with --dry-run', function () {
    writeTestApp('com_test_cli_config', ['theme' => 'default']);

    $application = cliApplication([new ConfigSetCommand()]);
    $tester = new CommandTester($application->find('config:set'));

    $status = $tester->execute([
        'package' => 'com_test_cli_config',
        'key' => 'theme',
        'value' => 'spark',
        '--dry-run' => true,
    ], ['interactive' => false]);

    expect($status)->toBe(0)
        ->and($tester->getDisplay())->toContain('dry-run');

    AppEngine::__rebuild();
    expect(AppEngine::config('com_test_cli_config')->get('theme'))->toBe('default');
});

it('refuses to change the app.php package key', function () {
    writeTestApp('com_test_cli_config', []);

    $application = cliApplication([new ConfigSetCommand()]);
    $tester = new CommandTester($application->find('config:set'));

    $status = $tester->execute([
        'package' => 'com_test_cli_config',
        'key' => 'package',
        'value' => 'com_other_app',
    ], ['interactive' => false]);

    expect($status)->not->toBe(0)
        ->and($tester->getDisplay())->toContain('package');
});
