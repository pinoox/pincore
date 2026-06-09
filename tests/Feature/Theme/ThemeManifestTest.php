<?php
use Pinoox\Component\Package\AppEnv\AppEnvBridge;
use Pinoox\Component\Package\Pinx\PinxBuilder;
use Pinoox\Component\Package\Pinx\PinxManifest;
use Pinoox\Component\Template\Theme\ThemeManifest;
use Pinoox\Component\Template\Theme\ThemeStack;
use Pinoox\Component\Test\AppTestKit;
use Pinoox\Portal\App\AppEngine;
beforeEach(function () {
    AppTestKit::boot();
    themeManifestDeleteApp('com_test_theme_manifest');
    AppEngine::__rebuild();
});
afterEach(function () {
    themeManifestDeleteApp('com_test_theme_manifest');
    AppEngine::__rebuild();
});
it('loads localized title and extends from theme.php', function () {
    themeManifestWriteApp([
        'toranj/theme.php' => themeManifestPhp([
            'name' => 'toranj',
            'package' => 'com_test_theme_manifest',
            'extends' => ['blue'],
            'developer' => 'pinoox',
            'title' => ['en' => 'Toranj', 'fa' => 'ترنج'],
            'description' => ['en' => 'Minimal blog template'],
            'version-name' => '1.0',
            'version-code' => 2,
            'api' => true,
        ]),
        'blue/theme.php' => themeManifestPhp([
            'name' => 'blue',
            'package' => 'com_test_theme_manifest',
            'title' => ['en' => 'Blue'],
        ]),
    ]);
    $manifest = ThemeManifest::load('com_test_theme_manifest', 'toranj');
    expect($manifest)->not->toBeNull()
        ->and($manifest->name())->toBe('toranj')
        ->and($manifest->hostPackage())->toBe('com_test_theme_manifest')
        ->and($manifest->extends())->toBe(['blue'])
        ->and($manifest->title('fa'))->toBe('ترنج')
        ->and($manifest->hasApiShell())->toBeTrue()
        ->and($manifest->versionCode())->toBe(2);
});
it('loads theme manifest through pinker with theme-source defaults', function () {
    themeManifestWriteApp([
        'toranj/theme.php' => themeManifestPhp([
            'name' => 'toranj',
            'package' => 'com_test_theme_manifest',
            'title' => ['en' => 'Toranj'],
        ]),
    ]);

    $sourceFile = AppTestKit::path('com_test_theme_manifest', 'theme/toranj/theme.php');
    $overrideFile = themeManifestPinkerOverrideFile($sourceFile);
    $overrideDir = dirname($overrideFile);

    if (!is_dir($overrideDir)) {
        mkdir($overrideDir, 0777, true);
    }

    file_put_contents($overrideFile, <<<'PHP'
<?php
return [
    '__pinker_override__' => true,
    'schema' => 1,
    'data' => [
        'developer' => 'pinker team',
    ],
    'remove' => [],
    'info' => [
        'updated_at' => 4102444800,
    ],
];
PHP);

    $manifest = ThemeManifest::load('com_test_theme_manifest', 'toranj');

    expect($manifest)->not->toBeNull()
        ->and($manifest->developer())->toBe('pinker team')
        ->and($manifest->copyright())->toBe('MIT')
        ->and($manifest->cover())->toBe('cover.png');

    @unlink($overrideFile);
    themeManifestDeletePinkerOverrideTree($sourceFile);
});
it('discovers installed themes with theme.php', function () {
    themeManifestWriteApp([
        'blue/theme.php' => themeManifestPhp([
            'name' => 'blue',
            'package' => 'com_test_theme_manifest',
            'title' => ['en' => 'Blue'],
        ]),
        'toranj/theme.php' => themeManifestPhp([
            'name' => 'toranj',
            'package' => 'com_test_theme_manifest',
            'title' => ['en' => 'Toranj'],
        ]),
    ]);
    $themes = ThemeManifest::discover('com_test_theme_manifest');
    expect(array_keys($themes))->toBe(['blue', 'toranj']);
});
it('embeds theme meta in pinx manifest for theme packages', function () {
    themeManifestWriteApp([
        'spark/theme.txt' => 'spark',
        'spark/theme.php' => themeManifestPhp([
            'name' => 'spark',
            'package' => 'com_test_theme_manifest',
            'developer' => 'pinoox team',
            'title' => ['en' => 'Spark'],
            'version-name' => '2.0',
            'version-code' => 3,
        ]),
    ], [
        'pinx' => [
            'type' => 'theme',
            'target_app' => 'com_test_theme_manifest',
            'theme_name' => 'spark',
        ],
        'theme' => 'spark',
    ]);
    AppEngine::__rebuild();
    $result = (new PinxBuilder(AppEngine::___()))->build(
        'com_test_theme_manifest',
        themeManifestTempFile('spark.pinx'),
    );
    $manifest = (new \Pinoox\Component\Package\Pinx\PinxReader())
        ->open($result['path'])
        ->manifest();
    expect($manifest->type())->toBe(PinxManifest::TYPE_THEME)
        ->and($manifest->package())->toBe('spark')
        ->and($manifest->targetApp())->toBe('com_test_theme_manifest')
        ->and($manifest->developer())->toBe('pinoox team')
        ->and($manifest->versionCode())->toBe(3);

    AppEnvBridge::reset();
    AppEngine::__rebuild();
});
it('builds inheritance stack from nested theme.php extends', function () {
    themeManifestWriteApp([
        'child/theme.php' => themeManifestPhp([
            'name' => 'child',
            'package' => 'com_test_theme_manifest',
            'extends' => ['parent'],
        ]),
        'parent/theme.php' => themeManifestPhp([
            'name' => 'parent',
            'package' => 'com_test_theme_manifest',
        ]),
        'child/page.twig' => 'child-page',
        'parent/layout.twig' => '<html>{% block body %}{% endblock %}</html>',
    ], ['theme' => 'child']);
    AppEngine::__rebuild();

    expect(ThemeStack::resolve('com_test_theme_manifest')['stack'])->toBe(['child', 'parent']);
});
function themeManifestPhp(array $data): string
{
    return "<?php\n\nreturn " . var_export($data, true) . ";\n";
}
function themeManifestWriteApp(array $themeFiles, array $appExtra = []): void
{
    $package = 'com_test_theme_manifest';
    $app = array_merge([
        'package' => $package,
        'enable' => true,
        'name' => 'Theme Manifest Test',
        'theme' => 'default',
        'router' => ['routes' => []],
    ], $appExtra);

    $files = [
        'app.php' => "<?php\n\nreturn " . var_export($app, true) . ";\n",
    ];

    foreach ($themeFiles as $relative => $content) {
        $files['theme/' . str_replace('\\', '/', $relative)] = $content;
    }

    AppTestKit::fakeApp($package, $files);
}

function themeManifestDeleteApp(string $package): void
{
    $sourceFile = AppTestKit::path($package, 'theme/toranj/theme.php');
    themeManifestDeletePinkerOverrideTree($sourceFile);
    AppTestKit::deleteFakeApp($package);
    themeManifestDeleteDirectory(testFixtures('theme_manifest'));
    AppEnvBridge::reset();
}

function themeManifestPinkerOverrideFile(string $sourceFile): string
{
    $baked = \Pinoox\Portal\Pinker::bakedFileFromSource($sourceFile);
    $pinkerRoot = rtrim(str_replace('\\', '/', \Pinoox\Support\SystemConfig::path('pinker')), '/');

    return $pinkerRoot . '/state/' . substr(str_replace('\\', '/', $baked), strlen($pinkerRoot) + 1);
}

function themeManifestDeletePinkerOverrideTree(string $sourceFile): void
{
    $overrideFile = themeManifestPinkerOverrideFile($sourceFile);
    $overrideDir = dirname($overrideFile);

    if (is_file($overrideFile)) {
        @unlink($overrideFile);
    }

    if (!is_dir($overrideDir)) {
        return;
    }

    themeManifestDeleteDirectory($overrideDir);

    $parent = dirname($overrideDir);
    while ($parent !== dirname($parent) && str_contains($parent, '/pinker/state/')) {
        if ((scandir($parent) ?: []) === ['.', '..']) {
            @rmdir($parent);
            $parent = dirname($parent);
            continue;
        }

        break;
    }
}

function themeManifestAppDir(): string
{
    return AppTestKit::path('com_test_theme_manifest');
}
function themeManifestTempFile(string $name): string
{
    $dir = testFixtures('theme_manifest');
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    return $dir . '/' . $name;
}
function themeManifestDeleteDirectory(string $dir): void
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

