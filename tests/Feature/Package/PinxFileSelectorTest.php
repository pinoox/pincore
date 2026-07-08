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
