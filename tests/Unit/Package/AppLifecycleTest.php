<?php

use Pinoox\Component\Package\Lifecycle\AppLifecycle;
use Pinoox\Component\Package\Lifecycle\AppLifecycleContext;
use Pinoox\Component\Package\Lifecycle\AppLifecyclePath;

function lifecycleTempDir(): string
{
    $dir = sys_get_temp_dir() . '/pinoox-lifecycle-' . bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    return $dir;
}

function lifecycleWrite(string $dir, string $name, string $contents): string
{
    $path = $dir . '/' . $name;
    file_put_contents($path, $contents);

    return $path;
}

afterEach(function () {
    $dir = $GLOBALS['_lifecycle_tmp'] ?? null;
    if (!is_string($dir) || !is_dir($dir)) {
        return;
    }

    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
    unset($GLOBALS['_lifecycle_tmp']);
});

it('resolves default lifecycle.php when config is true or omitted', function () {
    $dir = $GLOBALS['_lifecycle_tmp'] = lifecycleTempDir();
    $path = lifecycleWrite($dir, 'lifecycle.php', '<?php return null;');

    $normalized = str_replace('\\', '/', $path);

    expect(AppLifecyclePath::resolve(true, $dir))->toBe($normalized)
        ->and(AppLifecyclePath::resolve(null, $dir))->toBe($normalized);
});

it('returns null when lifecycle.php is missing or disabled', function () {
    $dir = $GLOBALS['_lifecycle_tmp'] = lifecycleTempDir();

    expect(AppLifecyclePath::resolve(true, $dir))->toBeNull()
        ->and(AppLifecyclePath::resolve(false, $dir))->toBeNull();
});

it('resolves a custom lifecycle path and skips when that file is missing', function () {
    $dir = $GLOBALS['_lifecycle_tmp'] = lifecycleTempDir();
    $path = lifecycleWrite($dir, 'custom-lifecycle.php', '<?php return null;');

    expect(AppLifecyclePath::resolve('custom-lifecycle.php', $dir))->toBe(str_replace('\\', '/', $path))
        ->and(AppLifecyclePath::resolve('missing.php', $dir))->toBeNull();
});

it('loads registrar closure handlers', function () {
    $dir = $GLOBALS['_lifecycle_tmp'] = lifecycleTempDir();
    $path = lifecycleWrite($dir, 'lifecycle.php', <<<'PHP'
<?php

use Pinoox\Component\Package\Lifecycle\AppLifecycle;

return function (AppLifecycle $life): void {
    $life->onInstall(static fn () => null);
    $life->onUpdate(static fn () => null);
};
PHP);

    $life = AppLifecycle::fromFile($path);

    expect($life->handlers(AppLifecycle::INSTALL))->toHaveCount(1)
        ->and($life->handlers(AppLifecycle::UPDATE))->toHaveCount(1)
        ->and($life->handlers(AppLifecycle::UNINSTALL))->toHaveCount(0)
        ->and($life->handlers(AppLifecycle::RESET))->toHaveCount(0);
});

it('loads action map returns', function () {
    $dir = $GLOBALS['_lifecycle_tmp'] = lifecycleTempDir();
    $path = lifecycleWrite($dir, 'lifecycle.php', <<<'PHP'
<?php

return [
    'install' => static fn () => null,
    'uninstall' => static fn () => null,
];
PHP);

    $life = AppLifecycle::fromFile($path);

    expect($life->handlers(AppLifecycle::INSTALL))->toHaveCount(1)
        ->and($life->handlers(AppLifecycle::UNINSTALL))->toHaveCount(1)
        ->and($life->handlers(AppLifecycle::UPDATE))->toHaveCount(0);
});

it('ignores a script with no return value', function () {
    $dir = $GLOBALS['_lifecycle_tmp'] = lifecycleTempDir();
    $path = lifecycleWrite($dir, 'lifecycle.php', "<?php\n\$GLOBALS['lifecycle_side'] = true;\n");

    $life = AppLifecycle::fromFile($path);

    expect($life->handlers(AppLifecycle::INSTALL))->toBe([])
        ->and($GLOBALS['lifecycle_side'])->toBeTrue();
});

it('runs only the matching action handler', function () {
    $dir = $GLOBALS['_lifecycle_tmp'] = lifecycleTempDir();
    $path = lifecycleWrite($dir, 'lifecycle.php', <<<'PHP'
<?php

use Pinoox\Component\Package\Lifecycle\AppLifecycle;
use Pinoox\Component\Package\Lifecycle\AppLifecycleContext;

return function (AppLifecycle $life): void {
    $life->onInstall(function (AppLifecycleContext $ctx): void {
        $GLOBALS['life_ran'][] = 'install:' . $ctx->package;
    });
    $life->onUpdate(function (AppLifecycleContext $ctx): void {
        $GLOBALS['life_ran'][] = 'update:' . $ctx->package;
    });
};
PHP);

    $GLOBALS['life_ran'] = [];
    $life = AppLifecycle::fromFile($path);
    $ctx = new AppLifecycleContext(package: 'com_acme_shop', action: AppLifecycle::INSTALL, toVersionCode: 2);

    foreach ($life->handlers(AppLifecycle::INSTALL) as $handler) {
        $handler($ctx);
    }

    expect($GLOBALS['life_ran'])->toBe(['install:com_acme_shop']);
});

it('returns empty lifecycle when file is missing', function () {
    $life = AppLifecycle::fromFile(sys_get_temp_dir() . '/pinoox-missing-lifecycle.php');

    expect($life->handlers(AppLifecycle::INSTALL))->toBe([]);
});
