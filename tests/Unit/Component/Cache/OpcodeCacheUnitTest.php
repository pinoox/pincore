<?php

use Pinoox\Component\Cache\Opcode\FakeOpcodeDriver;
use Pinoox\Component\Cache\Opcode\NativeOpcodeDriver;
use Pinoox\Component\Cache\OpcodeCache;
use Pinoox\Portal\Path;

afterEach(function () {
    OpcodeCache::restoreDefaultDriver();
});

it('detects OPcache availability correctly in fake driver', function () {
    $fakeEnabled = new FakeOpcodeDriver(available: true);
    $cache = new OpcodeCache($fakeEnabled);
    expect($cache->isAvailable())->toBeTrue();

    $fakeDisabled = new FakeOpcodeDriver(available: false);
    $cacheDisabled = new OpcodeCache($fakeDisabled);
    expect($cacheDisabled->isAvailable())->toBeFalse();
});

it('invalidates single PHP file with force = true in enabled scenario', function () {
    $fake = OpcodeCache::fake(available: true);
    $cache = new OpcodeCache();

    $tmpDir = sys_get_temp_dir() . '/pinoox_opcache_test_' . uniqid();
    mkdir($tmpDir, 0777, true);
    $tmpFile = $tmpDir . '/script.php';
    file_put_contents($tmpFile, "<?php echo 'ok';");

    $result = $cache->invalidateFile($tmpFile, true);

    expect($result)->toBeTrue()
        ->and($fake->invalidated)->toHaveCount(1)
        ->and($fake->invalidated[0]['force'])->toBeTrue()
        ->and($fake->hasInvalidated($tmpFile))->toBeTrue();

    @unlink($tmpFile);
    @rmdir($tmpDir);
});

it('skips invalidation and returns false for non-existent file', function () {
    $fake = OpcodeCache::fake(available: true);
    $cache = new OpcodeCache();

    $result = $cache->invalidateFile('/path/to/non/existent/script.php');

    expect($result)->toBeFalse()
        ->and($fake->invalidated)->toBeEmpty();
});

it('fails gracefully in disabled OPcache scenario', function () {
    $fake = OpcodeCache::fake(available: false);
    $cache = new OpcodeCache();

    $tmpDir = sys_get_temp_dir() . '/pinoox_opcache_disabled_' . uniqid();
    mkdir($tmpDir, 0777, true);
    $tmpFile = $tmpDir . '/script.php';
    file_put_contents($tmpFile, "<?php echo 'disabled';");

    expect($cache->isAvailable())->toBeFalse()
        ->and($cache->invalidateFile($tmpFile))->toBeFalse()
        ->and($cache->invalidateDirectory($tmpDir))->toBe(0)
        ->and($cache->invalidateApp('welcome'))->toBe(0)
        ->and($cache->invalidateCore())->toBe(0)
        ->and($cache->reset())->toBeFalse()
        ->and($fake->invalidated)->toBeEmpty()
        ->and($fake->resetCount)->toBe(0);

    @unlink($tmpFile);
    @rmdir($tmpDir);
});

it('invalidates directory recursively and ignores non-PHP files', function () {
    $fake = OpcodeCache::fake(available: true);
    $cache = new OpcodeCache();

    $tmpDir = sys_get_temp_dir() . '/pinoox_opcache_dir_' . uniqid();
    $subDir = $tmpDir . '/sub';
    mkdir($subDir, 0777, true);

    file_put_contents($tmpDir . '/one.php', "<?php return 1;");
    file_put_contents($subDir . '/two.php', "<?php return 2;");
    file_put_contents($tmpDir . '/ignore.txt', "text file");
    file_put_contents($subDir . '/config.json', "{}");

    $count = $cache->invalidateDirectory($tmpDir, true);

    expect($count)->toBe(2)
        ->and($fake->invalidated)->toHaveCount(2)
        ->and($fake->hasInvalidated($tmpDir . '/one.php'))->toBeTrue()
        ->and($fake->hasInvalidated($subDir . '/two.php'))->toBeTrue()
        ->and($fake->hasInvalidated($tmpDir . '/ignore.txt'))->toBeFalse();

    @unlink($tmpDir . '/one.php');
    @unlink($subDir . '/two.php');
    @unlink($tmpDir . '/ignore.txt');
    @unlink($subDir . '/config.json');
    @rmdir($subDir);
    @rmdir($tmpDir);
});

it('invalidates app package and pinker cache folders', function () {
    $fake = OpcodeCache::fake(available: true);
    $cache = new OpcodeCache();

    $count = $cache->invalidateApp('com_pinoox_welcome', true);

    expect($count)->toBeGreaterThan(0)
        ->and($fake->invalidated)->not->toBeEmpty();
});

it('invalidates core framework files', function () {
    $fake = OpcodeCache::fake(available: true);
    $cache = new OpcodeCache();

    $corePath = Path::pincore();
    expect(is_dir($corePath))->toBeTrue();

    $count = $cache->invalidateCore(true);

    expect($count)->toBeGreaterThan(10)
        ->and($fake->invalidated)->not->toBeEmpty();
});

it('does not reset OPcache by default and only resets when explicitly called', function () {
    $fake = OpcodeCache::fake(available: true);
    $cache = new OpcodeCache();

    // Default operations do NOT call reset
    $cache->invalidateFile(__FILE__);
    expect($fake->resetCount)->toBe(0);

    // Explicit reset
    $res = $cache->reset();
    expect($res)->toBeTrue()
        ->and($fake->resetCount)->toBe(1);
});

it('executes native opcode driver on active PHP CLI runtime safely', function () {
    $native = new NativeOpcodeDriver();
    $cache = new OpcodeCache($native);

    // Native driver isAvailable matches system
    $expected = function_exists('opcache_invalidate') && filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL);
    expect($cache->isAvailable())->toBe($expected);

    // Test on real temporary file
    $tmpDir = sys_get_temp_dir() . '/pinoox_native_test_' . uniqid();
    mkdir($tmpDir, 0777, true);
    $tmpFile = $tmpDir . '/test.php';
    file_put_contents($tmpFile, "<?php return 'hello';");

    $result = $cache->invalidateFile($tmpFile, true);

    if ($expected) {
        expect($result)->toBeTrue();
    } else {
        expect($result)->toBeFalse();
    }

    @unlink($tmpFile);
    @rmdir($tmpDir);
});
