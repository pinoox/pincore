<?php

use Pinoox\Component\Package\BuildPatternMatcher;
use Pinoox\Component\Package\Pinx\PinxFileSelector;
use Pinoox\Component\Package\Pinx\PlatformFileSelector;

it('matches gitignore-style exclude patterns in build matcher', function () {
    $matcher = new BuildPatternMatcher('/project', [
        '/tests',
        'docker',
        '*.log',
    ]);

    expect($matcher->isExcluded('tests/Case.php'))->toBeTrue()
        ->and($matcher->isExcluded('src/tests/Case.php'))->toBeFalse()
        ->and($matcher->isExcluded('docker/Dockerfile'))->toBeTrue()
        ->and($matcher->isExcluded('apps/com_demo/docker/file'))->toBeTrue()
        ->and($matcher->isExcluded('storage/logs/app.log'))->toBeTrue()
        ->and($matcher->isExcluded('index.php'))->toBeFalse();
});

it('applies include patterns as gitignore negation rules', function () {
    $matcher = new BuildPatternMatcher('/project', [
        '/apps/*',
        '!/apps/com_allowed/**',
    ], [
        'apps/com_allowed/**',
    ]);

    expect($matcher->isExcluded('apps/com_allowed/app.php'))->toBeFalse()
        ->and($matcher->isExcluded('apps/com_blocked/app.php'))->toBeTrue();
});

it('discovers force-included files from include patterns', function () {
    $root = sys_get_temp_dir() . '/build_include_' . uniqid('', true);
    mkdir($root . '/storage/apps/com_demo', 0777, true);
    mkdir($root . '/storage/logs', 0777, true);
    file_put_contents($root . '/storage/apps/com_demo/.gitkeep', '');
    file_put_contents($root . '/storage/logs/app.log', 'log');
    file_put_contents($root . '/index.php', '<?php');

    $matcher = new BuildPatternMatcher($root, ['/storage/*'], ['storage/**/.gitkeep']);
    $discovered = $matcher->discoverForcedIncludes();

    expect(array_keys($discovered))
        ->toContain('storage/apps/com_demo/.gitkeep')
        ->not->toContain('storage/logs/app.log');

    buildPatternDeleteDirectory($root);
});

it('applies build exclude patterns in platform payload selection', function () {
    $root = sys_get_temp_dir() . '/platform_build_patterns_' . uniqid('', true);
    mkdir($root . '/tests/unit', 0777, true);
    mkdir($root . '/docker', 0777, true);
    file_put_contents($root . '/index.php', '<?php');
    file_put_contents($root . '/tests/unit/Case.php', '<?php');
    file_put_contents($root . '/docker/Dockerfile', 'FROM php');

    $selector = new PlatformFileSelector();
    $files = $selector->payloadFiles($root, [
        'gitignore' => false,
        'exclude' => ['/tests', 'docker'],
        'include' => [],
        'exclude_theme_src' => false,
    ]);

    expect(array_keys($files))
        ->toContain('index.php')
        ->not->toContain('tests/unit/Case.php')
        ->not->toContain('docker/Dockerfile');

    buildPatternDeleteDirectory($root);
});

it('applies build include patterns in platform payload selection', function () {
    $root = sys_get_temp_dir() . '/platform_build_include_' . uniqid('', true);
    mkdir($root . '/apps/com_allowed', 0777, true);
    mkdir($root . '/apps/com_blocked', 0777, true);
    file_put_contents($root . '/index.php', '<?php');
    file_put_contents($root . '/apps/com_allowed/app.php', '<?php');
    file_put_contents($root . '/apps/com_blocked/app.php', '<?php');

    $selector = new PlatformFileSelector();
    $files = $selector->payloadFiles($root, [
        'gitignore' => false,
        'exclude' => ['/apps/*', '!/apps/com_allowed/**'],
        'include' => ['apps/com_allowed/**'],
        'exclude_theme_src' => false,
    ]);

    expect(array_keys($files))
        ->toContain('index.php')
        ->toContain('apps/com_allowed/app.php')
        ->not->toContain('apps/com_blocked/app.php');

    buildPatternDeleteDirectory($root);
});

it('applies build exclude patterns in pinx payload selection', function () {
    $root = sys_get_temp_dir() . '/pinx_build_patterns_' . uniqid('', true);
    mkdir($root . '/private', 0777, true);
    file_put_contents($root . '/app.php', '<?php');
    file_put_contents($root . '/private/secret.txt', 'secret');

    $selector = new PinxFileSelector();
    $files = $selector->payloadFiles($root, [
        'gitignore' => false,
        'exclude' => ['private/**'],
        'include' => [],
        'include_themes' => [],
    ]);

    expect(array_keys($files))
        ->toContain('app.php')
        ->not->toContain('private/secret.txt');

    buildPatternDeleteDirectory($root);
});

function buildPatternDeleteDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $full = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($full)) {
            buildPatternDeleteDirectory($full);
            continue;
        }

        @unlink($full);
    }

    @rmdir($path);
}
