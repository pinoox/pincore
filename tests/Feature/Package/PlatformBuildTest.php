<?php

use Pinoox\Component\Package\Pinx\PinxPaths;
use Pinoox\Component\Package\Pinx\PlatformBuildConfig;
use Pinoox\Component\Package\Pinx\PlatformComposer;
use Pinoox\Component\Package\Pinx\PlatformFileSelector;

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
        ->and($config['exclude'])->toContain('pincore');
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

it('does not list platform in apps-only package choices', function () {
    $command = new \Pinoox\Terminal\Pinx\PinxBuildCommand();
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('packageChoices');
    $method->setAccessible(true);

    $choices = $method->invoke($command, excludeSystem: true, appsOnly: true);

    expect($choices)->not->toHaveKey('platform');
});
