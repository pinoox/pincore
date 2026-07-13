<?php

use Pinoox\Component\Template\Frontend\ThemeFrontend;
use Pinoox\Component\Template\Frontend\ThemeFrontendPaths;
use Pinoox\Component\Test\AppTestKit;
use Pinoox\Portal\App\AppEngine;

beforeEach(function () {
    AppTestKit::boot();
    deleteThemeFrontendPathsApp('com_test_fe_paths');
    AppEngine::__rebuild();
});

afterEach(function () {
    deleteThemeFrontendPathsApp('com_test_fe_paths');
    AppEngine::__rebuild();
});

it('lists theme folders from AppEngine app root (HMVC apps/{package})', function () {
    writeThemeFrontendPathsApp([
        'theme' => 'spark',
    ], [
        'spark/package.json' => json_encode(['scripts' => ['dev' => 'vite']], JSON_THROW_ON_ERROR),
    ]);
    AppEngine::__rebuild();

    $appRoot = AppEngine::path('com_test_fe_paths');

    expect(ThemeFrontendPaths::themesRoot('com_test_fe_paths'))->toBe($appRoot . '/theme')
        ->and(ThemeFrontendPaths::themeDir('com_test_fe_paths', 'spark'))->toBe($appRoot . '/theme/spark')
        ->and(ThemeFrontend::listThemeFolders('com_test_fe_paths'))->toHaveKey('spark');
});

it('lists theme folders when the app root is registered outside apps/', function () {
    $package = 'com_test_fe_paths';
    $appRoot = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/pinoox_fe_paths_' . uniqid('', true);

    if (!is_dir($appRoot . '/theme/panel')) {
        mkdir($appRoot . '/theme/panel', 0777, true);
    }

    file_put_contents($appRoot . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'name' => 'Root App', 'theme' => 'panel'];\n");
    file_put_contents($appRoot . '/theme/panel/package.json', json_encode(['scripts' => ['dev' => 'vite']], JSON_THROW_ON_ERROR));

    try {
        AppEngine::add($package, $appRoot);

        expect(AppEngine::path($package))->toBe($appRoot)
            ->and(ThemeFrontendPaths::themesRoot($package))->toBe($appRoot . '/theme')
            ->and(ThemeFrontend::listThemeFolders($package))->toHaveKey('panel');
    } finally {
        themeFrontendPathsDeleteDirectory($appRoot);
    }
});

it('includes registry app paths in AppEngine packagePaths', function () {
    writeThemeFrontendPathsApp(['theme' => 'spark'], [
        'spark/package.json' => json_encode(['scripts' => ['dev' => 'vite']], JSON_THROW_ON_ERROR),
    ]);
    AppEngine::__rebuild();

    $package = 'com_test_fe_paths';
    $appRoot = AppEngine::path($package);

    expect(AppEngine::packagePaths())
        ->toHaveKey($package)
        ->and(AppEngine::packagePaths()[$package])->toBe($appRoot);
});

function writeThemeFrontendPathsApp(array $config, array $themeFiles = []): void
{
    $package = 'com_test_fe_paths';
    $app = array_merge([
        'package' => $package,
        'enable' => true,
        'name' => 'Frontend Paths Test',
        'version-code' => 1,
        'router' => ['routes' => []],
    ], $config);

    $files = [
        'app.php' => "<?php\n\nreturn " . var_export($app, true) . ";\n",
    ];

    foreach ($themeFiles as $relative => $content) {
        $files['theme/' . $relative] = $content;
    }

    AppTestKit::fakeApp($package, $files);
}

function deleteThemeFrontendPathsApp(string $package): void
{
    AppTestKit::deleteFakeApp($package);
}

function themeFrontendPathsDeleteDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}
