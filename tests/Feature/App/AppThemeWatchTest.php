<?php

use Pinoox\Component\AppEvent\AppBootstrap;
use Pinoox\Component\AppEvent\AppWatchContext;
use Pinoox\Component\AppEvent\AppWatchRegistry;
use Pinoox\Component\Template\Theme\ThemeContext;
use Pinoox\Component\Test\AppTestKit;
use Pinoox\Portal\App\AppEngine;

beforeEach(function () {
    AppTestKit::boot();
    ThemeContext::clearAll();
    AppBootstrap::resetState();
    deleteThemeWatchTestApp('com_test_theme_watch');
    AppEngine::__rebuild();
});

afterEach(function () {
    ThemeContext::clearAll();
    AppBootstrap::resetState();
    deleteThemeWatchTestApp('com_test_theme_watch');
    AppEngine::__rebuild();
});

it('collects onTheme watches from boot.php', function () {
    writeThemeWatchTestApp([
        'theme-context' => 'site',
        'theme-contexts' => [
            'site' => ['theme' => 'site'],
            'panel' => ['theme' => 'panel'],
        ],
    ], bootFile: <<<'PHP'
$register->onTheme('panel', function (AppWatchContext $ctx): void {});
PHP);

    AppBootstrap::markKernelReady();
    AppBootstrap::ensure('com_test_theme_watch', true);

    expect(AppWatchRegistry::rules())->toHaveCount(1)
        ->and(AppWatchRegistry::rules()[0]['kind'])->toBe('theme')
        ->and(AppWatchRegistry::rules()[0]['match'])->toBe('panel');
});

it('runs theme watch when context activates', function () {
    writeThemeWatchTestApp([
        'theme-context' => 'site',
        'theme-contexts' => [
            'site' => ['theme' => 'site'],
            'panel' => ['theme' => 'panel'],
        ],
    ], bootFile: <<<'PHP'
$register->onTheme('panel', function (AppWatchContext $ctx): void {
    file_put_contents(__DIR__ . '/.theme-watch-fired', ($ctx->themeContext() ?? '') . ':' . ($ctx->themeName() ?? ''));
});
PHP);

    AppBootstrap::markKernelReady();
    AppBootstrap::ensure('com_test_theme_watch', true);

    inApp('com_test_theme_watch', function () {
        ThemeContext::activate('panel', 'com_test_theme_watch');
    });

    $marker = AppTestKit::path('com_test_theme_watch') . '/.theme-watch-fired';
    expect(is_file($marker))->toBeTrue()
        ->and(trim((string) file_get_contents($marker)))->toBe('panel:panel');

    @unlink($marker);
});

it('matches theme folder name for apps without contexts', function () {
    writeThemeWatchTestApp([
        'theme' => 'default',
    ], bootFile: <<<'PHP'
$register->onTheme('default', function (AppWatchContext $ctx): void {
    file_put_contents(__DIR__ . '/.theme-watch-default', $ctx->themeName() ?? 'missing');
});
PHP);

    AppBootstrap::markKernelReady();
    AppBootstrap::ensure('com_test_theme_watch', true);

    inApp('com_test_theme_watch', function () {
        AppWatchRegistry::dispatchTheme('com_test_theme_watch');
    });

    $marker = AppTestKit::path('com_test_theme_watch') . '/.theme-watch-default';
    expect(is_file($marker))->toBeTrue()
        ->and(trim((string) file_get_contents($marker)))->toBe('default');

    @unlink($marker);
});

function writeThemeWatchTestApp(array $config, ?string $bootFile = null): void
{
    $package = 'com_test_theme_watch';
    $app = array_merge([
        'package' => $package,
        'enable' => true,
        'name' => 'Theme Watch Test',
        'version-code' => 1,
        'router' => ['routes' => []],
        'boot' => true,
    ], $config);

    $files = [
        'app.php' => "<?php\n\nreturn " . var_export($app, true) . ";\n",
    ];

    if ($bootFile !== null) {
        $files['boot.php'] = <<<PHP
<?php

use Pinoox\Component\AppEvent\AppRegister;
use Pinoox\Component\AppEvent\AppWatchContext;

return function (AppRegister \$register): void {
    {$bootFile}
};
PHP;
    }

    foreach (['site', 'panel', 'default'] as $theme) {
        $files['theme/' . $theme . '/.gitkeep'] = '';
    }

    AppTestKit::fakeApp($package, $files);
}

function deleteThemeWatchTestApp(string $package): void
{
    AppTestKit::deleteFakeApp($package);
}
