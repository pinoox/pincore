<?php

use Pinoox\Component\Http\Request;
use Pinoox\Component\Package\App;
use Pinoox\Component\Package\AppRouter;
use Pinoox\Component\Package\Engine\AppEngine as PackageAppEngine;
use Pinoox\Component\Package\Parser\NameParser;
use Pinoox\Component\Path\Path;
use Pinoox\Component\Path\Url;
use Pinoox\Component\Store\Config\ConfigInterface;
use Pinoox\Support\AppPublicPath;
use Pinoox\Support\AppRegistry;
use Pinoox\Support\SystemConfig;

beforeEach(function () {
    SystemConfig::clearCache();
});

afterEach(function () {
    deleteAppPublicPathFixtures();
});

it('resolves empty public prefix for in-tree apps registered with ~', function () {
    $basePath = appPublicPathFixtureRoot();
    $package = 'com_test_intree_assets';
    appPublicPathBootstrapIntreeApp($basePath, $package);

    $registry = AppRegistry::fromArray(['packages' => [$package => '~']], $basePath);
    $engine = new PackageAppEngine($basePath . '/apps_unused', 'app.php', appPublicPathPinkerPath(), null, $registry);

    expect(AppPublicPath::prefix($engine, $package, $basePath))->toBe('')
        ->and($engine->packagePaths())->toHaveKey($package)
        ->and(AppPublicPath::packageForPath($engine, $basePath . '/theme/blue/dist/assets/main.css', $basePath))
        ->toBe($package);
});

it('builds theme asset urls without apps prefix for in-tree apps', function () {
    $basePath = appPublicPathFixtureRoot();
    $package = 'com_test_intree_urls';
    appPublicPathBootstrapIntreeApp($basePath, $package);

    $registry = AppRegistry::fromArray(['packages' => [$package => '~']], $basePath);
    $engine = new PackageAppEngine($basePath . '/apps_unused', 'app.php', appPublicPathPinkerPath(), null, $registry);
    $request = Request::create('http://localhost/', 'GET');
    $request->server->set('HTTP_HOST', 'localhost');

    $url = appPublicPathMakeUrl($request, $engine, $basePath, $package, '/');

    expect($url->asset('theme/blue/dist/assets/main.css', $package))
        ->toBe('http://localhost/theme/blue/dist/assets/main.css')
        ->and($url->assetPath('theme/blue/dist/assets/main.css', $package))
        ->toBe('/theme/blue/dist/assets/main.css')
        ->and($url->asset('apps/' . $package . '/theme/blue/dist/assets/main.css', $package))
        ->toBe('http://localhost/theme/blue/dist/assets/main.css');
});

it('keeps apps prefix for standard folder apps', function () {
    $basePath = appPublicPathFixtureRoot();
    $package = 'com_test_folder_assets';
    $appsPath = $basePath . '/apps';
    $appRoot = $appsPath . '/' . $package;

    mkdir($appRoot . '/resources', 0777, true);
    file_put_contents($appRoot . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'enable' => true];\n");
    file_put_contents($appRoot . '/resources/icon.png', 'png');

    $engine = new PackageAppEngine($appsPath, 'app.php', appPublicPathPinkerPath());
    $request = Request::create('http://localhost/', 'GET');
    $request->server->set('HTTP_HOST', 'localhost');

    $url = appPublicPathMakeUrl($request, $engine, $basePath, $package, '/');

    expect(AppPublicPath::prefix($engine, $package, $basePath))->toBe('apps/' . $package)
        ->and($url->asset('resources/icon.png', $package))
        ->toBe('http://localhost/apps/' . $package . '/resources/icon.png');
});

function appPublicPathFixtureRoot(): string
{
    return str_replace('\\', '/', testFixtures('app_public_path'));
}

function appPublicPathPinkerPath(): string
{
    return testRuntimePinker();
}

function appPublicPathBootstrapIntreeApp(string $basePath, string $package): void
{
    if (!is_dir($basePath)) {
        mkdir($basePath, 0777, true);
    }

    file_put_contents($basePath . '/app.php', "<?php\n\nreturn ['package' => '{$package}', 'theme' => 'blue', 'enable' => true, 'path-theme' => 'theme'];\n");

    $assetDir = $basePath . '/theme/blue/dist/assets';
    if (!is_dir($assetDir)) {
        mkdir($assetDir, 0777, true);
    }

    file_put_contents($assetDir . '/main.css', 'body{}');
}

function appPublicPathMakeUrl(
    Request $request,
    PackageAppEngine $engine,
    string $basePath,
    string $package,
    string $routePath,
): Url {
    $appRouter = new AppRouter(new AppPublicPathTestConfig([]), $engine, $request);
    $path = new Path($basePath, new NameParser(), $engine, $package);

    /** @var App $app */
    $app = test()->createMock(App::class);
    $app->method('package')->willReturn($package);
    $app->method('pathRoute')->willReturn($routePath);

    return new Url($app, $request, $appRouter, $path, $basePath);
}

function deleteAppPublicPathFixtures(): void
{
    $dir = appPublicPathFixtureRoot();

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

class AppPublicPathTestConfig implements ConfigInterface
{
    public function __construct(private array $data = [])
    {
    }

    public function get(?string $key = null, $default = null): mixed
    {
        if ($key === null) {
            return $this->data;
        }

        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): static
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function remove(string $key): static
    {
        unset($this->data[$key]);

        return $this;
    }

    public function save(): static
    {
        return $this;
    }

    public function setData(mixed $data): static
    {
        $this->data = is_array($data) ? $data : [];

        return $this;
    }
}
