<?php

use Pinoox\Component\Kernel\Loader;
use Pinoox\Portal\App\AppProvider;
use Pinoox\Portal\Path;

beforeEach(function () {
    Loader::setBasePath(testProjectRoot());
    AppProvider::___();
});

it('declares the Path portal contract', function () {
    expectPortalContract(Path::class);
});

it('resolves system app paths through aliases', function () {
    $basePath = testProjectRoot();
    $corePath = testCoreRoot();
    $projectConfig = rtrim(str_replace('\\', '/', \Pinoox\Support\SystemConfig::projectConfigPath()), '/');

    expect(Path::___())->toBeInstanceOf(\Pinoox\Component\Path\Path::class)
        ->and(Path::get('~config/pinoox.config.php'))
        ->toBe($projectConfig . '/pinoox.config.php')
        ->and(Path::root())
        ->toBe($basePath)
        ->and(Path::system('pinoox.config.php'))
        ->toBe($projectConfig . '/pinoox.config.php')
        ->and(Path::pincore('config/pinoox.config.php'))
        ->toBe($corePath . '/config/pinoox.config.php')
        ->and(Path::pincore('config/pincore.config.php'))
        ->toBe($corePath . '/config/pincore.config.php');
});

it('resolves named references through resolve()', function () {
    expect(Path::resolve('~config/app/source.config.php'))
        ->toBe(testCoreRoot() . '/config/app/source.config.php');
});

