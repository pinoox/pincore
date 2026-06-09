<?php

/**
 * Shared helpers for Database feature tests — avoids duplicate global functions across test files.
 */

use Pinoox\Component\Test\AppTestKit;

function writeTestApp(string $package, array $config): void
{
    $payload = [
        'package' => $package,
        'enable' => true,
        'name' => $package,
        ...$config,
    ];

    AppTestKit::fakeApp($package, [
        'app.php' => "<?php\n\nreturn " . var_export($payload, true) . ";\n",
    ]);
}

function deleteTestApp(string $package): void
{
    AppTestKit::deleteFakeApp($package);
}

/** @deprecated Use {@see deleteTestApp()} */
function deleteDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
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
