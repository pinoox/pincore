<?php

use Illuminate\Container\Container;
use Pinoox\Component\Database\DatabaseManager;
use Pinoox\Component\Kernel\Loader;
use Pinoox\Model\Table;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Support\DevApp;
use Pinoox\Support\SystemConfig;
use Pinoox\Terminal\Concerns\SelectsPackage;

afterEach(function () {
    singleAppPinxRestoreProjectRoot();
});

it('registers a root app.php single app as a normal app package', function () {
    $root = singleAppPinxProjectRoot('registry');
    singleAppPinxWriteApp($root);
    singleAppPinxUseRoot($root, false);

    $registered = singleAppPinxRegisteredPackages();

    expect(DevApp::package())->toBe('com_test_single_pinx')
        ->and(DevApp::defaultCliPackage())->toBe('com_test_single_pinx')
        ->and($registered)->toHaveKey('com_test_single_pinx')
        ->and($registered['com_test_single_pinx'])->toBe($root);

    AppEngine::add('com_test_single_pinx', $root);

    expect(AppEngine::exists('com_test_single_pinx'))->toBeTrue()
        ->and(AppEngine::path('com_test_single_pinx'))->toBe($root);
});

it('resolves the root single app through AppEngine when registered at boot', function () {
    $root = singleAppPinxProjectRoot('engine');
    singleAppPinxWriteApp($root);
    singleAppPinxUseRoot($root);

    expect(AppEngine::exists('com_test_single_pinx'))->toBeTrue()
        ->and(AppEngine::path('com_test_single_pinx'))->toBe($root);
});

it('uses core DB_PREFIX for platform tables and app table.prefix for single app tables', function () {
    $root = singleAppPinxProjectRoot('prefix');
    singleAppPinxWriteApp($root);
    singleAppPinxUseRoot($root);

    $manager = new DatabaseManager(new Container());
    $manager->registerCoreConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'pinx_',
    ]);

    $connectionName = $manager->connectionNameForPackage('com_test_single_pinx');

    expect($connectionName)->toBe('app_com_test_single_pinx_default')
        ->and($manager->app('com_test_single_pinx')->getTablePrefix())->toBe('notiq_')
        ->and($manager->tableName('rolls', 'com_test_single_pinx'))->toBe('rolls')
        ->and($manager->physicalTableName('rolls', 'com_test_single_pinx'))->toBe('notiq_rolls')
        ->and($manager->tableName(Table::USER, 'platform'))->toBe('user')
        ->and($manager->physicalTableName(Table::USER, 'platform'))->toBe('pinx_user');
});

it('creates plain migration schema tables with the single app table prefix', function () {
    $root = singleAppPinxProjectRoot('schema');
    singleAppPinxWriteApp($root);
    singleAppPinxUseRoot($root);

    $manager = new DatabaseManager(new Container());
    $manager->registerCoreConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'pinx_',
    ]);

    $schema = $manager->app('com_test_single_pinx')->getSchemaBuilder();
    $schema->create('rolls', function ($table) {
        $table->increments('roll_id');
    });

    $tables = array_map(
        static fn ($row) => $row->name,
        $manager->app('com_test_single_pinx')->select("select name from sqlite_master where type = 'table'")
    );

    expect($tables)->toContain('notiq_rolls')
        ->not->toContain('pinx_notiq_rolls');
});

it('uses the root single app package as the non-interactive CLI default', function () {
    $root = singleAppPinxProjectRoot('cli');
    singleAppPinxWriteApp($root);
    singleAppPinxUseRoot($root);

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
        ['excludeSystem' => true],
    );

    expect($package)->toBe('com_test_single_pinx');
});

function singleAppPinxProjectRoot(string $suffix): string
{
    $root = testSandbox('single-app-pinx/' . $suffix);
    singleAppPinxDeleteDirectory($root);
    mkdir($root, 0777, true);

    return str_replace('\\', '/', $root);
}

function singleAppPinxWriteApp(string $root): void
{
    file_put_contents($root . '/app.php', "<?php\n\nreturn " . var_export([
        'package' => 'com_test_single_pinx',
        'enable' => true,
        'name' => 'Single Pinx Test',
        'database' => null,
        'table' => [
            'prefix' => 'notiq_',
        ],
    ], true) . ";\n");
}

function singleAppPinxUseRoot(string $root, bool $registerApp = true): void
{
    Loader::setBasePath($root);
    SystemConfig::clearCache();
    AppEngine::__rebuild();

    if ($registerApp) {
        AppEngine::add('com_test_single_pinx', $root);
    }
}

function singleAppPinxRestoreProjectRoot(): void
{
    Loader::setBasePath(defined('PINOOX_BASE_PATH') ? PINOOX_BASE_PATH : testProjectRoot());
    SystemConfig::clearCache();
    AppEngine::__rebuild();
}

function singleAppPinxDeleteDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($dir);
}

function singleAppPinxRegisteredPackages(): array
{
    $method = new ReflectionMethod(AppEngine::class, 'registeredPackages');
    $method->setAccessible(true);

    return $method->invoke(null);
}
