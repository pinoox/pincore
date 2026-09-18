<?php

use Pinoox\Component\Cache\AppCacheManager;
use Pinoox\Component\Cache\AppCachePath;
use Pinoox\Component\Cache\PhpCacheFile;
use Pinoox\Component\Package\AppProvisioner;
use Pinoox\Component\Test\AppTestKit;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\OpcodeCache;
use Pinoox\Terminal\Cache\OpcodeCacheCommand;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    AppTestKit::boot();
    deleteTestApp('com_test_opcache_feat');
    AppEngine::__rebuild();
});

afterEach(function () {
    OpcodeCache::restoreDefaultDriver();
    deleteTestApp('com_test_opcache_feat');
    AppEngine::__rebuild();
});

it('resolves opcode cache portal methods', function () {
    $fake = OpcodeCache::fake(available: true);

    expect(OpcodeCache::isAvailable())->toBeTrue();

    $tmp = sys_get_temp_dir() . '/test_portal_opcache.php';
    file_put_contents($tmp, "<?php echo 1;");

    expect(OpcodeCache::invalidateFile($tmp))->toBeTrue()
        ->and($fake->hasInvalidated($tmp))->toBeTrue();

    @unlink($tmp);
});

it('automatically invalidates OPcache when PhpCacheFile writes or unlinks', function () {
    $fake = OpcodeCache::fake(available: true);

    $path = sys_get_temp_dir() . '/pinker_opcache_test_' . uniqid() . '/sample.php';

    PhpCacheFile::write($path, ['status' => 'active']);

    expect(is_file($path))->toBeTrue()
        ->and($fake->hasInvalidated($path))->toBeTrue();

    $fake->clear();

    PhpCacheFile::unlink($path);

    expect(is_file($path))->toBeFalse()
        ->and($fake->hasInvalidated($path))->toBeTrue();

    @rmdir(dirname($path));
});

it('automatically invalidates OPcache when AppCacheManager clears cache', function () {
    $fake = OpcodeCache::fake(available: true);

    $package = 'com_test_opcache_clear';
    $root = AppCachePath::root($package);
    if (!is_dir($root)) {
        mkdir($root, 0777, true);
    }
    file_put_contents($root . '/routes.php', "<?php return [];");

    AppCacheManager::clear($package);

    $normalizedRoot = str_replace('\\', '/', $root);
    $hasInvalidated = false;
    foreach ($fake->invalidatedFiles() as $file) {
        if (str_starts_with(str_replace('\\', '/', $file), $normalizedRoot)) {
            $hasInvalidated = true;
            break;
        }
    }

    expect($hasInvalidated)->toBeTrue();
});

it('automatically triggers targeted OPcache invalidation in AppProvisioner', function () {
    writeTestApp('com_test_opcache_feat', [
        'name' => 'OpCache Test App',
    ]);
    AppEngine::__rebuild();

    $fake = OpcodeCache::fake(available: true);

    $provisioner = new AppProvisioner(AppEngine::__instance());
    $provisioner->provision('com_test_opcache_feat', [
        'skip_migrate' => true,
        'skip_patch' => true,
        'skip_lifecycle' => true,
        'skip_cache' => true,
    ]);

    expect($fake->invalidated)->not->toBeEmpty();
});

it('executes opcache:clear CLI command for core, file, and app targets', function () {
    $fake = OpcodeCache::fake(available: true);

    $command = new OpcodeCacheCommand();
    $tester = new CommandTester($command);

    // 1. Core target
    $tester->execute(['--core' => true]);
    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('core PHP file(s) in OPcache');

    // 2. Specific file target
    $tmpFile = sys_get_temp_dir() . '/test_cli_opcache.php';
    file_put_contents($tmpFile, "<?php echo 'cli';");

    $tester->execute(['--file' => $tmpFile]);
    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Invalidated opcode for file')
        ->and($fake->hasInvalidated($tmpFile))->toBeTrue();

    @unlink($tmpFile);

    // 3. App package target
    $tester->execute(['package' => 'com_pinoox_welcome']);
    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain('Targeted OPcache Invalidation');
});
