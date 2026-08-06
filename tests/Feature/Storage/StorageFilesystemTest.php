<?php

use Pinoox\Component\File;
use Pinoox\Component\Storage\StorageSetup;
use Pinoox\Component\Store\Config\ConfigInterface;
use Pinoox\Component\Store\FileSystem\FilesystemManager;
use Pinoox\Support\SystemConfig;

beforeEach(function () {
    SystemConfig::clearCache();
    deleteStorageFilesystemTestDirectory(str_replace('\\', '/', testFixtures('storage_local')));
});

afterEach(function () {
    SystemConfig::clearCache();
    deleteStorageFilesystemTestDirectory(str_replace('\\', '/', testFixtures('storage_local')));
});

it('writes multi-server deny rules when the storage root is first created', function () {
    $root = str_replace('\\', '/', testFixtures('storage_root_guard'));

    deleteStorageFilesystemTestDirectory($root);

    expect(File::ensureStorageRootProtection($root))->toBeTrue()
        ->and(is_file($root . '/.htaccess'))->toBeTrue()
        ->and(is_file($root . '/web.config'))->toBeTrue()
        ->and(is_file($root . '/nginx.conf'))->toBeTrue()
        ->and(is_file($root . '/Caddyfile'))->toBeTrue()
        ->and(file_get_contents($root . '/.htaccess'))->toContain('Require all denied')
        ->and(file_get_contents($root . '/web.config'))->toContain('<deny users="*" />')
        ->and(file_get_contents($root . '/nginx.conf'))->toContain('location ^~ /storage/');

    expect(File::ensureStorageRootProtection($root))->toBeTrue();

    deleteStorageFilesystemTestDirectory($root);
});

it('creates app scoped filesystems under the local disk root', function () {
    $root = str_replace('\\', '/', testFixtures('storage_local'));

    $manager = new FilesystemManager(new ArrayConfig([
        'default' => 'local',
        'app_disk' => 'local',
        'app_root' => testFixturesProjectRelative('storage_local'),
        'disks' => [
            'local' => [
                'driver' => 'local',
                'root' => testFixturesProjectRelative('storage_local'),
                'protect' => 'lock',
                'throw' => true,
            ],
        ],
    ]));

    expect($manager->appPath('com_pinoox_manager'))->toBe($root . '/com_pinoox_manager');

    $disk = $manager->app('com_pinoox_manager');
    $disk->put('notes/readme.txt', 'hello pinoox');

    expect(is_file($root . '/com_pinoox_manager/notes/readme.txt'))->toBeTrue()
        ->and(file_get_contents($root . '/com_pinoox_manager/notes/readme.txt'))->toBe('hello pinoox');

    deleteStorageFilesystemTestDirectory($root);
});

it('normalizes protect lock/unlock aliases', function () {
    expect(StorageSetup::normalizeProtect(null))->toBe('lock')
        ->and(StorageSetup::normalizeProtect(''))->toBe('lock')
        ->and(StorageSetup::normalizeProtect('lock'))->toBe('lock')
        ->and(StorageSetup::normalizeProtect('deny'))->toBe('lock')
        ->and(StorageSetup::normalizeProtect('private'))->toBe('lock')
        ->and(StorageSetup::normalizeProtect('unlock'))->toBe('unlock')
        ->and(StorageSetup::normalizeProtect('allow'))->toBe('unlock')
        ->and(StorageSetup::normalizeProtect(true))->toBe('lock')
        ->and(StorageSetup::normalizeProtect(false))->toBe('unlock');
});

class ArrayConfig implements ConfigInterface
{
    public function __construct(private array $items)
    {
    }

    public function get(?string $key = null, $default = null): mixed
    {
        if ($key === null || $key === '') {
            return $this->items;
        }

        $data = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return $default;
            }

            $data = $data[$segment];
        }

        return $data;
    }

    public function set(string $key, mixed $value): static
    {
        return $this;
    }

    public function remove(string $key): static
    {
        return $this;
    }

    public function save(): static
    {
        return $this;
    }
}

function deleteStorageFilesystemTestDirectory(string $dir): void
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

