<?php

use Pinoox\Component\Template\Frontend\ThemeFrontendDevTarget;
use Pinoox\Component\Test\AppTestKit;
use Pinoox\Portal\App\AppEngine;

beforeEach(function () {
    AppTestKit::boot();
    deleteThemeFrontendDevTargetApp('com_test_fe_ctx');
    AppEngine::__rebuild();
});

afterEach(function () {
    deleteThemeFrontendDevTargetApp('com_test_fe_ctx');
    AppEngine::__rebuild();
});

it('lists theme contexts instead of raw inheritance folders for fe dev', function () {
    writeThemeFrontendDevTargetApp([
        'theme-context' => 'site',
        'theme-contexts' => [
            'site' => ['theme' => 'site'],
            'panel' => ['theme' => 'panel', 'frontend' => ['stack' => 'vue']],
        ],
    ], [
        'panel/package.json' => json_encode([
            'scripts' => ['dev' => 'vite'],
            'devDependencies' => ['vite' => '^6.0.0', 'vue' => '^3.5.0'],
        ], JSON_THROW_ON_ERROR),
        'panel/frontend.config.php' => "<?php\n\nreturn ['stack' => 'vue'];\n",
        'base/package.json' => json_encode(['name' => 'base-only'], JSON_THROW_ON_ERROR),
    ]);
    AppEngine::__rebuild();

    $choices = ThemeFrontendDevTarget::choices('com_test_fe_ctx', true);

    expect($choices)->toHaveKey('panel')
        ->and($choices)->not->toHaveKey('base')
        ->and($choices)->not->toHaveKey('site');
});

it('resolves context names to theme folders and merges frontend config', function () {
    writeThemeFrontendDevTargetApp([
        'theme-context' => 'panel',
        'theme-contexts' => [
            'panel' => [
                'theme' => 'panel',
                'frontend' => ['entry' => 'src/admin.js'],
            ],
        ],
    ], [
        'panel/package.json' => json_encode(['scripts' => ['dev' => 'vite']], JSON_THROW_ON_ERROR),
        'panel/frontend.config.php' => "<?php\n\nreturn ['stack' => 'vue', 'entry' => 'src/main.js'];\n",
    ]);
    AppEngine::__rebuild();

    $resolved = ThemeFrontendDevTarget::resolve('com_test_fe_ctx', 'panel');
    $frontend = \Pinoox\Component\Template\Frontend\ThemeFrontend::forPackageAndTheme(
        'com_test_fe_ctx',
        $resolved['theme'],
        $resolved['context'],
    );

    expect($resolved)->toBe(['theme' => 'panel', 'context' => 'panel'])
        ->and($frontend->config()['entry'])->toBe('src/admin.js');
});

it('expands platform targets for each vite-capable context', function () {
    writeThemeFrontendDevTargetApp([
        'theme-context' => 'site',
        'theme-contexts' => [
            'site' => ['theme' => 'site'],
            'panel' => ['theme' => 'panel', 'frontend' => ['stack' => 'vue']],
            'kids' => ['theme' => 'kids', 'frontend' => ['stack' => 'vue']],
        ],
    ], [
        'panel/package.json' => json_encode(['scripts' => ['dev' => 'vite']], JSON_THROW_ON_ERROR),
        'panel/frontend.config.php' => "<?php\n\nreturn ['stack' => 'vue'];\n",
        'kids/package.json' => json_encode(['scripts' => ['dev' => 'vite']], JSON_THROW_ON_ERROR),
        'kids/frontend.config.php' => "<?php\n\nreturn ['stack' => 'vue'];\n",
    ]);
    AppEngine::__rebuild();

    $targets = ThemeFrontendDevTarget::targetsForPackage('com_test_fe_ctx');

    expect($targets)->toHaveCount(2)
        ->and(array_column($targets, 'context'))->toBe(['panel', 'kids']);
});

it('treats all-context selection as every vite-capable theme context', function () {
    writeThemeFrontendDevTargetApp([
        'theme-context' => 'site',
        'theme-contexts' => [
            'site' => [
                'theme' => 'landing',
                'frontend' => ['stack' => 'vite', 'profile' => 'hybrid'],
            ],
            'panel' => [
                'theme' => 'panel',
                'frontend' => ['stack' => 'vue', 'profile' => 'spa'],
            ],
        ],
    ], [
        'landing/package.json' => json_encode(['scripts' => ['dev' => 'vite']], JSON_THROW_ON_ERROR),
        'landing/frontend.config.php' => "<?php\n\nreturn ['stack' => 'vite'];\n",
        'panel/package.json' => json_encode(['scripts' => ['dev' => 'vite']], JSON_THROW_ON_ERROR),
        'panel/frontend.config.php' => "<?php\n\nreturn ['stack' => 'vue'];\n",
    ]);
    AppEngine::__rebuild();

    expect(ThemeFrontendDevTarget::isAllContexts('all'))->toBeTrue()
        ->and(ThemeFrontendDevTarget::hasMultipleViteContexts('com_test_fe_ctx'))->toBeTrue()
        ->and(ThemeFrontendDevTarget::targetsForPackage('com_test_fe_ctx', ThemeFrontendDevTarget::ALL_CONTEXTS))
        ->toHaveCount(2)
        ->and(array_column(
            ThemeFrontendDevTarget::targetsForPackage('com_test_fe_ctx', 'all'),
            'context',
        ))->toBe(['site', 'panel']);
});

function writeThemeFrontendDevTargetApp(array $config, array $themeFiles = []): void
{
    $package = 'com_test_fe_ctx';
    $app = array_merge([
        'package' => $package,
        'enable' => true,
        'name' => 'Frontend Context Test',
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

function deleteThemeFrontendDevTargetApp(string $package): void
{
    AppTestKit::deleteFakeApp($package);
}
