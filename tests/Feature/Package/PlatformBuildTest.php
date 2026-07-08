<?php

use Pinoox\Component\Package\AppComposerVendor;
use Pinoox\Component\Package\Pinx\PinxPaths;
use Pinoox\Component\Package\Pinx\PlatformBuildConfig;
use Pinoox\Component\Package\Pinx\PlatformComposer;
use Pinoox\Component\Package\Pinx\PlatformFileSelector;
use Pinoox\Component\Package\Pinx\PlatformStorageScaffold;
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
        ->and($config['exclude'])->toContain('storage');
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

it('prepares storage skeleton in platform build workspace', function () {
    $root = sys_get_temp_dir() . '/platform_storage_' . uniqid('', true);
    mkdir($root . '/storage', 0777, true);
    file_put_contents($root . '/storage/.htaccess', 'deny');
    file_put_contents($root . '/storage/web.config', 'iis');

    $files = PlatformStorageScaffold::prepare($root);
    $workspace = PlatformStorageScaffold::workspaceDir($root);

    expect($workspace)->toEndWith('/storage/.platform-build/skeleton')
        ->and(is_file($workspace . '/.htaccess'))->toBeTrue()
        ->and(is_file($workspace . '/logs/.gitkeep'))->toBeTrue()
        ->and($files)->toHaveKey('.htaccess')
        ->and($files)->toHaveKey('logs/.gitkeep');

    PlatformComposer::cleanup($root);
    expect(is_dir(PlatformBuildConfig::buildPath($root)))->toBeFalse();
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
    file_put_contents($root . '/composer.json', json_encode([
        'name' => 'pinoox/pinoox',
        'require' => ['php' => '^8.2'],
    ]));
    file_put_contents($root . '/vendor/autoload.php', '<?php');
    file_put_contents($root . '/vendor/composer/installed.json', json_encode(['packages' => []]));

    $result = PlatformComposer::prepare($root, stripRequireDev: false);

    expect($result['prepared'])->toBeTrue()
        ->and(is_file(PlatformComposer::vendorPath($root) . '/autoload.php'))->toBeTrue();

    PlatformComposer::cleanup($root);
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
