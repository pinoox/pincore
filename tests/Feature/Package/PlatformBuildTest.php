<?php

use Pinoox\Component\Package\AppComposerVendor;
use Pinoox\Component\Package\ComposerVendorGuard;
use Pinoox\Component\Package\Pinx\PinxPaths;
use Pinoox\Component\Package\Pinx\PlatformBuildConfig;
use Pinoox\Component\Package\Pinx\PlatformComposer;
use Pinoox\Component\Package\Pinx\PlatformFileSelector;
use Pinoox\Component\Package\Pinx\PlatformVendorMaterializer;

it('resolves platform build defaults from build.config.php', function () {
    $root = sys_get_temp_dir() . '/platform_build_cfg_' . uniqid('', true);
    mkdir($root . '/platform', 0777, true);
    file_put_contents($root . '/platform/build.config.php', <<<'PHP'
<?php
return [
    'gitignore' => false,
    'exclude' => ['custom-dir'],
    'composer' => false,
    'exclude_theme_src' => false,
];
PHP);

    $config = PlatformBuildConfig::resolve($root);

    expect($config['gitignore'])->toBeFalse()
        ->and($config['composer'])->toBeFalse()
        ->and($config['exclude_theme_src'])->toBeFalse()
        ->and($config['exclude'])->toContain('custom-dir')
        ->and($config['exclude'])->toContain('pincore')
        ->and($config['exclude'])->toContain('storage/.platform-build')
        ->and($config['exclude'])->not->toContain('storage');
});

it('strips require-dev from distribution composer.json', function () {
    $root = sys_get_temp_dir() . '/platform_composer_' . uniqid('', true);
    mkdir($root, 0777, true);

    file_put_contents($root . '/composer.json', json_encode([
        'name' => 'pinoox/pinoox',
        'require' => ['php' => '^8.2', 'pinoox/pincore' => '^3.4'],
        'require-dev' => ['pestphp/pest' => '^2.0'],
        'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']],
        'scripts' => ['test' => '@php vendor/bin/pest'],
    ], JSON_PRETTY_PRINT));

    $distribution = PlatformComposer::distributionComposer($root . '/composer.json');

    expect($distribution)->toHaveKey('require')
        ->and($distribution)->not->toHaveKey('require-dev')
        ->and($distribution)->not->toHaveKey('autoload-dev')
        ->and($distribution)->not->toHaveKey('scripts')
        ->and($distribution['require'])->toHaveKey('pinoox/pincore');
});

it('excludes gitignore files and theme src from platform payload selection', function () {
    $root = sys_get_temp_dir() . '/platform_selector_' . uniqid('', true);
    mkdir($root . '/apps/com_demo/theme/spark/src', 0777, true);
    mkdir($root . '/apps/com_demo/theme/spark/dist', 0777, true);

    file_put_contents($root . '/index.php', '<?php');
    file_put_contents($root . '/.gitignore', "/vendor/\n");
    file_put_contents($root . '/apps/com_demo/.gitignore', "node_modules/\n");
    file_put_contents($root . '/apps/com_demo/theme/spark/src/main.js', 'export {};');
    file_put_contents($root . '/apps/com_demo/theme/spark/dist/app.js', 'console.log(1);');
    file_put_contents($root . '/apps/com_demo/theme/spark/theme.php', '<?php return [];');

    $selector = new PlatformFileSelector();
    $files = $selector->payloadFiles($root, [
        'gitignore' => false,
        'exclude' => [],
        'include' => [],
        'exclude_theme_src' => true,
    ]);

    expect(array_keys($files))
        ->toContain('index.php')
        ->toContain('apps/com_demo/theme/spark/dist/app.js')
        ->not->toContain('.gitignore')
        ->not->toContain('apps/com_demo/.gitignore')
        ->not->toContain('apps/com_demo/theme/spark/src/main.js');
});

it('uses .zip filename for platform export path', function () {
    $filename = PinxPaths::defaultPlatformReleaseFilename();

    expect(str_ends_with($filename, '.zip'))->toBeTrue()
        ->and($filename)->toStartWith('pinoox_');
});

it('includes htaccess files in platform payload selection', function () {
    $root = sys_get_temp_dir() . '/platform_htaccess_' . uniqid('', true);
    mkdir($root . '/apps/com_demo', 0777, true);

    file_put_contents($root . '/.htaccess', 'root');
    file_put_contents($root . '/apps/com_demo/.htaccess', 'app');
    file_put_contents($root . '/index.php', '<?php');

    $selector = new PlatformFileSelector();
    $files = $selector->payloadFiles($root, [
        'gitignore' => true,
        'exclude' => [],
        'include' => [],
        'exclude_theme_src' => true,
    ]);

    expect(array_keys($files))
        ->toContain('.htaccess')
        ->toContain('apps/com_demo/.htaccess');
});

it('respects nested gitignore rules in platform payload selection', function () {
    $root = sys_get_temp_dir() . '/platform_nested_gitignore_' . uniqid('', true);
    mkdir($root . '/apps/com_demo/private', 0777, true);
    mkdir($root . '/apps/com_demo/public', 0777, true);

    file_put_contents($root . '/.gitignore', "/vendor/\n");
    file_put_contents($root . '/apps/com_demo/.gitignore', "private/\n");
    file_put_contents($root . '/apps/com_demo/private/secret.txt', 'secret');
    file_put_contents($root . '/apps/com_demo/public/page.php', '<?php');
    file_put_contents($root . '/index.php', '<?php');

    $selector = new PlatformFileSelector();
    $files = $selector->payloadFiles($root, [
        'gitignore' => true,
        'exclude' => [],
        'include' => [],
        'exclude_theme_src' => false,
    ]);

    expect(array_keys($files))
        ->toContain('index.php')
        ->toContain('apps/com_demo/public/page.php')
        ->not->toContain('apps/com_demo/private/secret.txt');
});

it('includes storage skeleton files according to gitignore rules', function () {
    $root = sys_get_temp_dir() . '/platform_storage_gitignore_' . uniqid('', true);
    mkdir($root . '/storage/logs', 0777, true);
    mkdir($root . '/storage/apps/com_demo', 0777, true);

    file_put_contents($root . '/.gitignore', <<<'GITIGNORE'
/storage/*
!/storage/.gitkeep
!/storage/.htaccess
!/storage/**/.gitkeep
GITIGNORE);
    file_put_contents($root . '/storage/.htaccess', 'deny');
    file_put_contents($root . '/storage/.gitkeep', '');
    file_put_contents($root . '/storage/logs/app.log', 'log');
    file_put_contents($root . '/storage/apps/com_demo/.gitkeep', '');
    file_put_contents($root . '/index.php', '<?php');

    $selector = new PlatformFileSelector();
    $files = $selector->payloadFiles($root, [
        'gitignore' => true,
        'exclude' => [],
        'include' => [],
        'exclude_theme_src' => false,
    ]);

    expect(array_keys($files))
        ->toContain('storage/.htaccess')
        ->toContain('storage/.gitkeep')
        ->toContain('storage/apps/com_demo/.gitkeep')
        ->not->toContain('storage/logs/app.log');
});

it('discovers composer path repositories for pinoox packages', function () {
    $root = sys_get_temp_dir() . '/platform_path_repo_' . uniqid('', true);
    mkdir($root . '/packages/pinion', 0777, true);
    file_put_contents($root . '/packages/pinion/composer.json', json_encode([
        'name' => 'pinoox/pinion',
        'type' => 'library',
    ]));

    file_put_contents($root . '/composer.json', json_encode([
        'name' => 'pinoox/pinoox',
        'repositories' => [[
            'type' => 'path',
            'url' => 'packages/pinion',
        ]],
        'require' => [
            'php' => '^8.2',
            'pinoox/pinion' => '*',
        ],
    ]));

    $packages = PlatformVendorMaterializer::discoverPathPackages($root);

    expect($packages)->toHaveKey('pinoox/pinion')
        ->and($packages['pinoox/pinion'])->toEndWith('/packages/pinion');
});

it('requires composer vendor before platform build', function () {
    $root = sys_get_temp_dir() . '/platform_vendor_guard_' . uniqid('', true);
    mkdir($root, 0777, true);
    file_put_contents($root . '/composer.json', json_encode([
        'name' => 'pinoox/pinoox',
        'require' => ['php' => '^8.2', 'pinoox/pincore' => '^3.4'],
    ]));

    expect(fn () => PlatformComposer::prepare($root))
        ->toThrow(\Pinoox\Component\Kernel\Exception::class, 'Composer vendor is not installed');
});

it('copies installed vendor for platform build', function () {
    $root = sys_get_temp_dir() . '/platform_vendor_copy_' . uniqid('', true);
    mkdir($root . '/vendor/composer', 0777, true);
    mkdir($root . '/vendor/acme/pkg/tests', 0777, true);
    mkdir($root . '/vendor/acme/pkg/src', 0777, true);
    file_put_contents($root . '/composer.json', json_encode([
        'name' => 'pinoox/pinoox',
        'require' => ['php' => '^8.2'],
    ]));
    file_put_contents($root . '/vendor/autoload.php', '<?php');
    file_put_contents($root . '/vendor/composer/installed.json', json_encode(['packages' => []]));
    file_put_contents($root . '/vendor/acme/pkg/tests/CaseTest.php', '<?php');
    file_put_contents($root . '/vendor/acme/pkg/src/Run.php', '<?php');

    $result = PlatformComposer::prepare($root, stripRequireDev: false, vendorPrune: true);

    expect($result['prepared'])->toBeTrue()
        ->and(is_file(PlatformComposer::vendorPath($root) . '/autoload.php'))->toBeTrue()
        ->and(is_file(PlatformComposer::vendorPath($root) . '/acme/pkg/src/Run.php'))->toBeTrue()
        ->and(is_dir(PlatformComposer::vendorPath($root) . '/acme/pkg/tests'))->toBeFalse();

    PlatformComposer::cleanup($root);
});

it('excludes installed dev packages from bundled vendor tree', function () {
    $root = sys_get_temp_dir() . '/platform_vendor_dev_' . uniqid('', true);
    $target = $root . '/staging/vendor';
    mkdir($root . '/vendor/composer', 0777, true);
    mkdir($root . '/vendor/acme/pkg/src', 0777, true);
    mkdir($root . '/vendor/acme/dev-tool/src', 0777, true);
    file_put_contents($root . '/composer.json', json_encode([
        'name' => 'pinoox/pinoox',
        'require' => ['php' => '^8.2', 'acme/pkg' => '^1.0'],
        'require-dev' => ['acme/dev-tool' => '^1.0'],
    ]));
    file_put_contents($root . '/vendor/autoload.php', '<?php');
    file_put_contents($root . '/vendor/acme/pkg/src/Run.php', '<?php');
    file_put_contents($root . '/vendor/acme/dev-tool/src/Dev.php', '<?php');
    file_put_contents($root . '/vendor/composer/installed.php', <<<'PHP'
<?php return [
    'root' => ['dev' => true],
    'versions' => [
        'acme/pkg' => [
            'pretty_version' => '1.0.0',
            'version' => '1.0.0.0',
            'reference' => 'abc',
            'type' => 'library',
            'install_path' => __DIR__ . '/../acme/pkg',
            'aliases' => [],
            'dev_requirement' => false,
        ],
        'acme/dev-tool' => [
            'pretty_version' => '1.0.0',
            'version' => '1.0.0.0',
            'reference' => 'def',
            'type' => 'library',
            'install_path' => __DIR__ . '/../acme/dev-tool',
            'aliases' => [],
            'dev_requirement' => true,
        ],
    ],
];
PHP);

    expect(ComposerVendorGuard::installedDevPackageNames($root))
        ->toBe(['acme/dev-tool'])
        ->and(ComposerVendorGuard::installedDevVendorPaths($root))
        ->toBe(['acme/dev-tool']);

    ComposerVendorGuard::copyVendorTree(
        $root . '/vendor',
        $target,
        false,
        ComposerVendorGuard::installedDevVendorPaths($root),
    );

    expect(is_file($target . '/acme/pkg/src/Run.php'))->toBeTrue()
        ->and(is_file($target . '/acme/dev-tool/src/Dev.php'))->toBeFalse();
});

it('resolves vendor_prune from build.config.php', function () {
    $root = sys_get_temp_dir() . '/platform_vendor_prune_cfg_' . uniqid('', true);
    mkdir($root . '/platform', 0777, true);
    file_put_contents($root . '/platform/build.config.php', <<<'PHP'
<?php
return ['vendor_prune' => false];
PHP);

    expect(PlatformBuildConfig::resolve($root)['vendor_prune'])->toBeFalse();
});

it('requires composer vendor before app pinx build', function () {
    $root = sys_get_temp_dir() . '/app_vendor_guard_' . uniqid('', true);
    mkdir($root, 0777, true);
    file_put_contents($root . '/composer.json', json_encode([
        'name' => 'vendor/test-app',
        'require' => ['monolog/monolog' => '^3.0'],
    ]));

    expect(fn () => AppComposerVendor::prepare($root))
        ->toThrow(\Pinoox\Component\Kernel\Exception::class, 'Composer vendor is not installed');
});

it('does not list platform in apps-only package choices', function () {
    $command = new \Pinoox\Terminal\Pinx\PinxBuildCommand();
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('packageChoices');
    $method->setAccessible(true);

    $choices = $method->invoke($command, excludeSystem: true, appsOnly: true);

    expect($choices)->not->toHaveKey('platform');
});
