<?php

use Pinoox\Component\Package\Pinx\PinxFileSelector;

it('excludes nested node_modules from pinx payload selection', function () {
    $root = sys_get_temp_dir() . '/pinx_selector_' . uniqid('', true);
    $themeDir = $root . '/theme/demo';
    $nodeDir = $themeDir . '/node_modules/large';
    mkdir($nodeDir, 0777, true);
    file_put_contents($root . '/app.php', '<?php return [];');
    file_put_contents($themeDir . '/index.html', '<html></html>');
    file_put_contents($nodeDir . '/bundle.js', str_repeat('x', 1024));

    $selector = new PinxFileSelector();
    $files = $selector->payloadFiles($root, [
        'gitignore' => false,
        'exclude' => [],
        'include_themes' => [],
    ]);

    expect(array_keys($files))->toBe(['app.php', 'theme/demo/index.html']);

    pinxSelectorDeleteDirectory($root);
});

it('respects nested gitignore rules in pinx payload selection', function () {
    $root = sys_get_temp_dir() . '/pinx_nested_gitignore_' . uniqid('', true);
    mkdir($root . '/theme/spark/private', 0777, true);
    mkdir($root . '/theme/spark/public', 0777, true);
    file_put_contents($root . '/.gitignore', "vendor/\n");
    file_put_contents($root . '/theme/spark/.gitignore', "private/\n");
    file_put_contents($root . '/theme/spark/private/secret.txt', 'secret');
    file_put_contents($root . '/theme/spark/public/page.php', '<?php');
    file_put_contents($root . '/app.php', '<?php');

    $selector = new PinxFileSelector();
    $files = $selector->payloadFiles($root, [
        'gitignore' => true,
        'exclude' => [],
        'include_themes' => [],
    ]);

    expect(array_keys($files))
        ->toContain('app.php')
        ->toContain('theme/spark/public/page.php')
        ->not->toContain('theme/spark/private/secret.txt');

    pinxSelectorDeleteDirectory($root);
});

it('respects nested theme gitignore for dot directories in pinx payload selection', function () {
    $root = sys_get_temp_dir() . '/pinx_theme_dot_gitignore_' . uniqid('', true);
    mkdir($root . '/theme/welcome/.pinoox/cache', 0777, true);
    mkdir($root . '/theme/welcome/dist', 0777, true);
    file_put_contents($root . '/theme/welcome/.gitignore', ".pinoox/\n");
    file_put_contents($root . '/theme/welcome/.pinoox/cache/data.json', '{}');
    file_put_contents($root . '/theme/welcome/dist/app.js', 'js');
    file_put_contents($root . '/app.php', '<?php');

    $selector = new PinxFileSelector();
    $files = $selector->payloadFiles($root, [
        'gitignore' => true,
        'exclude' => [],
        'include_themes' => [],
    ]);

    expect(array_keys($files))
        ->toContain('theme/welcome/dist/app.js')
        ->not->toContain('theme/welcome/.pinoox/cache/data.json')
        ->not->toContain('theme/welcome/.gitignore');

    pinxSelectorDeleteDirectory($root);
});

it('respects nested gitignore when building from a theme source path', function () {
    $root = sys_get_temp_dir() . '/pinx_theme_root_gitignore_' . uniqid('', true);
    mkdir($root . '/private', 0777, true);
    mkdir($root . '/dist', 0777, true);
    file_put_contents($root . '/.gitignore', "private/\n.env\n");
    file_put_contents($root . '/private/secret.txt', 'secret');
    file_put_contents($root . '/dist/app.js', 'js');
    file_put_contents($root . '/.env', 'APP=1');
    file_put_contents($root . '/theme.php', '<?php');

    $selector = new PinxFileSelector();
    $files = $selector->payloadFiles($root, [
        'gitignore' => true,
        'exclude' => [],
        'include_themes' => [],
    ]);

    expect(array_keys($files))
        ->toContain('dist/app.js')
        ->toContain('theme.php')
        ->not->toContain('private/secret.txt')
        ->not->toContain('.env');

    pinxSelectorDeleteDirectory($root);
});

function pinxSelectorDeleteDirectory(string $path): void
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
            pinxSelectorDeleteDirectory($full);
            continue;
        }

        @unlink($full);
    }

    @rmdir($path);
}
