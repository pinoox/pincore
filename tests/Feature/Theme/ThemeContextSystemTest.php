<?php

use Pinoox\Component\Helpers\PinooxScriptHelper;
use Pinoox\Component\Template\Theme\ThemeContext;
use Pinoox\Component\Template\Theme\ThemeContextRegistry;
use Pinoox\Component\Template\Theme\ThemeStack;
use Pinoox\Component\Template\View;
use Pinoox\Component\Test\AppTestKit;
use Pinoox\Component\User\AuthConfig;
use Pinoox\Flow\ThemeContextFlow;
use Pinoox\Portal\App\AppEngine;

beforeEach(function () {
    AppTestKit::boot();
    ThemeContext::clearAll();
    deleteThemeContextTestApp('com_test_theme_ctx');
    AppEngine::__rebuild();
});

afterEach(function () {
    ThemeContext::clearAll();
    AuthConfig::reset();
    deleteThemeContextTestApp('com_test_theme_ctx');
    AppEngine::__rebuild();
});

it('resolves different theme folders per context', function () {
    writeThemeContextTestApp([
        'theme-context' => 'site',
        'theme-contexts' => [
            'site' => ['theme' => 'site'],
            'panel' => ['theme' => 'panel'],
            'kids' => ['theme' => 'kids', 'extends' => 'site'],
        ],
    ]);
    AppEngine::__rebuild();

    $site = ThemeStack::resolve('com_test_theme_ctx', 'site');
    $panel = ThemeStack::resolve('com_test_theme_ctx', 'panel');
    $kids = ThemeStack::resolve('com_test_theme_ctx', 'kids');

    expect(basename($site['paths'][0]))->toBe('site')
        ->and(basename($panel['paths'][0]))->toBe('panel')
        ->and($kids['stack'])->toBe(['kids', 'site']);
});

it('activates a theme context and switches view paths', function () {
    writeThemeContextTestApp([
        'theme-context' => 'site',
        'theme-contexts' => [
            'site' => ['theme' => 'site'],
            'panel' => ['theme' => 'panel'],
        ],
    ], [
        'site/page.twig' => 'SITE',
        'panel/page.twig' => 'PANEL',
    ]);
    AppEngine::__rebuild();

    ThemeContext::activate('site', 'com_test_theme_ctx');
    $siteView = new View(ThemeStack::resolve('com_test_theme_ctx', 'site')['paths'], '', []);
    expect($siteView->render('page.twig'))->toBe('SITE');

    ThemeContext::activate('panel', 'com_test_theme_ctx');
    $panelView = new View(ThemeStack::resolve('com_test_theme_ctx', 'panel')['paths'], '', []);
    expect($panelView->render('page.twig'))->toBe('PANEL');
});

it('builds theme flow aliases for route collections', function () {
    $aliases = theme_flow_aliases(['site', 'panel', 'kids']);

    expect($aliases['theme']['panel'])->toBeInstanceOf(ThemeContextFlow::class)
        ->and($aliases['theme']['site'])->toBeInstanceOf(ThemeContextFlow::class);
});

it('keeps backward compatibility when theme-contexts is empty', function () {
    writeThemeContextTestApp([
        'theme' => 'default',
        'theme-contexts' => [],
    ], [
        'default/page.twig' => 'DEFAULT',
    ]);
    AppEngine::__rebuild();

    expect(ThemeContextRegistry::hasContexts(include appThemeContextDir() . '/app.php'))->toBeFalse()
        ->and(ThemeStack::resolve('com_test_theme_ctx')['name'])->toBe('default');
});

it('restores previous context after within_theme()', function () {
    writeThemeContextTestApp([
        'theme-context' => 'site',
        'theme-contexts' => [
            'site' => ['theme' => 'site'],
            'panel' => ['theme' => 'panel'],
        ],
    ]);
    AppEngine::__rebuild();

    ThemeContext::activate('site', 'com_test_theme_ctx');

    within_theme('panel', function () {
        expect(ThemeContext::active('com_test_theme_ctx'))->toBe('panel');
    }, 'com_test_theme_ctx');

    expect(ThemeContext::active('com_test_theme_ctx'))->toBe('site');
});

it('merges path and auth from theme context into effectiveConfig', function () {
    $config = [
        'theme' => 'default',
        'auth' => [
            'mode' => 'jwt',
            'client' => true,
        ],
        'theme-contexts' => [
            'panel' => [
                'theme' => 'panel',
                'path' => 'panel',
                'auth' => [
                    'client' => ['loginUrl' => '/panel/auth/login'],
                ],
            ],
        ],
    ];

    $merged = ThemeContextRegistry::effectiveConfig($config, 'panel');

    expect($merged['theme'])->toBe('panel')
        ->and($merged['path'])->toBe('panel')
        ->and($merged['auth']['mode'])->toBe('jwt')
        ->and($merged['auth']['client'])->toBe(['loginUrl' => '/panel/auth/login']);
});

it('exposes context loginUrl via AuthConfig and context path for bootstrap AREA/BASE', function () {
    writeThemeContextTestApp([
        'theme-context' => 'site',
        'auth' => [
            'mode' => 'jwt',
            'key' => 'theme_ctx_test',
            'client' => true,
        ],
        'theme-contexts' => [
            'site' => [
                'path' => '',
                'theme' => 'site',
                'auth' => [
                    'client' => ['loginUrl' => '/login'],
                ],
            ],
            'panel' => [
                'path' => 'panel',
                'theme' => 'panel',
                'auth' => [
                    'client' => ['loginUrl' => '/panel/auth/login'],
                ],
            ],
        ],
    ]);
    AppEngine::__rebuild();

    \Pinoox\Portal\App\App::___()->setLayer(
        new \Pinoox\Component\Package\AppLayer('/', 'com_test_theme_ctx')
    );
    AuthConfig::reset();

    ThemeContext::activate('panel', 'com_test_theme_ctx');

    $auth = AuthConfig::forClient();
    expect($auth)->not->toBeNull()
        ->and($auth['loginUrl'] ?? null)->toBe('/panel/auth/login')
        ->and($auth['mode'])->toBe('jwt');

    $pathMethod = new ReflectionMethod(PinooxScriptHelper::class, 'activeContextPath');
    expect($pathMethod->invoke(null))->toBe('panel');

    ThemeContext::activate('site', 'com_test_theme_ctx');
    AuthConfig::reset();

    $siteAuth = AuthConfig::forClient();
    expect($siteAuth['loginUrl'] ?? null)->toBe('/login')
        ->and($pathMethod->invoke(null))->toBeNull();
});

function writeThemeContextTestApp(array $config, array $themeFiles = []): void
{
    $package = 'com_test_theme_ctx';
    $app = array_merge([
        'package' => $package,
        'enable' => true,
        'name' => 'Theme Context Test',
        'version-code' => 1,
        'router' => ['routes' => []],
    ], $config);

    $files = [
        'app.php' => "<?php\n\nreturn " . var_export($app, true) . ";\n",
    ];

    foreach ($themeFiles as $relative => $content) {
        $files['theme/' . $relative] = $content;
    }

    foreach (['site', 'panel', 'kids', 'default'] as $theme) {
        $marker = 'theme/' . $theme . '/.gitkeep';
        if (!isset($files[$marker])) {
            $files[$marker] = '';
        }
    }

    AppTestKit::fakeApp($package, $files);
}

function deleteThemeContextTestApp(string $package): void
{
    AppTestKit::deleteFakeApp($package);
}

function appThemeContextDir(): string
{
    return AppTestKit::path('com_test_theme_ctx');
}

function themeContextDeleteDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

