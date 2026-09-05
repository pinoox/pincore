<?php

use Pinoox\Component\File as FileHelper;
use Pinoox\Component\File\FileConfig;
use Pinoox\Component\File\FileDispatcher;
use Pinoox\Component\File\FilePolicy;
use Pinoox\Component\File\FileStorage;
use Pinoox\Component\File\FileTemporaryUrl;
use Pinoox\Component\File\Manager;
use Pinoox\Component\Storage\StorageSetup;
use Pinoox\Component\Store\Config\ConfigInterface;
use Pinoox\Component\Store\FileSystem\FilesystemManager;
use Pinoox\Model\FileModel;
use Pinoox\Portal\File;
use Pinoox\Support\SystemConfig;
use Pinoox\Terminal\Storage\StorageLinkCommand;
use Pinoox\Terminal\Storage\StorageSetupCommand;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    FileDispatcher::clearAuth();
    SystemConfig::clearCache();
});

afterEach(function () {
    FileDispatcher::clearAuth();
    SystemConfig::clearCache();
});

it('generates an 8-char hex hash_id by default', function () {
    $hash = FileModel::generateHash();

    expect($hash)->toHaveLength(8)
        ->and(ctype_xdigit($hash))->toBeTrue();
});

it('respects explicit hash_id length', function () {
    expect(FileModel::generateHash(16))->toHaveLength(16)
        ->and(FileModel::generateHash(4))->toHaveLength(4)
        ->and(FileConfig::clampHashLength(2))->toBe(4)
        ->and(FileConfig::clampHashLength(99))->toBe(50);
});

it('resolves manager find by file_id or hash_id signature', function () {
    $method = new ReflectionMethod(Manager::class, 'find');
    $type = $method->getParameters()[0]->getType();

    expect($type)->toBeInstanceOf(ReflectionUnionType::class)
        ->and(array_map(fn (ReflectionType $t) => $t->getName(), $type->getTypes()))
        ->toContain('int')
        ->toContain('string');
});

it('registers storage setup CLI aliases', function () {
    $application = cliApplication([new StorageSetupCommand()]);

    expect($application->has('storage:setup'))->toBeTrue()
        ->and($application->has('storage:lock'))->toBeTrue()
        ->and($application->has('storage:unlock'))->toBeTrue();
});

it('registers storage link CLI aliases', function () {
    $application = cliApplication([new StorageLinkCommand()]);

    expect($application->has('storage:link'))->toBeTrue()
        ->and($application->has('storage:unlink'))->toBeTrue();
});

it('applies unlock protect stubs for public storage', function () {
    $root = str_replace('\\', '/', testFixtures('storage_public_guard'));
    $public = $root . '/public';

    fileDispatcherDeleteDir($root);
    expect(FileHelper::ensureStorageRootProtection($root))->toBeTrue();

    expect(StorageSetup::applyProtect($public, 'unlock'))->toBeTrue()
        ->and(is_file($public . '/.htaccess'))->toBeTrue()
        ->and(file_get_contents($public . '/.htaccess'))->toContain('Require all granted')
        ->and(file_get_contents($root . '/.htaccess'))->toContain('Require all denied');

    fileDispatcherDeleteDir($root);
});

it('scopes public disk packages under storage/public', function () {
    $root = str_replace('\\', '/', testFixtures('storage_public_apps'));

    $manager = new FilesystemManager(new FileDispatcherArrayConfig([
        'default' => 'public',
        'app_disk' => 'public',
        'app_root' => testFixturesProjectRelative('storage_local'),
        'public_root' => testFixturesProjectRelative('storage_public_apps'),
        'disks' => [
            'public' => [
                'driver' => 'local',
                'root' => testFixturesProjectRelative('storage_public_apps'),
                'url' => 'http://example.test/storage/public',
                'visibility' => 'public',
                'protect' => 'unlock',
                'throw' => true,
            ],
            'local' => [
                'driver' => 'local',
                'root' => testFixturesProjectRelative('storage_local'),
                'protect' => 'lock',
                'throw' => true,
            ],
        ],
    ]));

    expect($manager->publicPath('com_demo'))->toBe($root . '/com_demo');

    $disk = $manager->app('com_demo', 'public');
    $disk->put('avatars/a.txt', 'ok');

    expect(is_file($root . '/com_demo/avatars/a.txt'))->toBeTrue()
        ->and($disk->url('avatars/a.txt'))->toContain('/storage/public/com_demo/avatars/a.txt');

    fileDispatcherDeleteDir($root);
});

it('scopes custom unlocked disks under storage/{disk} with a public url', function () {
    $root = str_replace('\\', '/', testFixtures('storage_media_apps'));

    $manager = new FilesystemManager(new FileDispatcherArrayConfig([
        'default' => 'local',
        'app_disk' => 'local',
        'app_root' => testFixturesProjectRelative('storage_local'),
        'public_root' => testFixturesProjectRelative('storage_public_apps'),
        'disks' => [
            'media' => [
                'driver' => 'local',
                'root' => testFixturesProjectRelative('storage_media_apps'),
                'url' => 'http://example.test/storage/media',
                'visibility' => 'public',
                'protect' => 'unlock',
                'throw' => true,
            ],
            'local' => [
                'driver' => 'local',
                'root' => testFixturesProjectRelative('storage_local'),
                'protect' => 'lock',
                'throw' => true,
            ],
        ],
    ]));

    $disk = $manager->app('com_demo', 'media');
    $disk->put('banners/a.txt', 'ok');

    expect(is_file($root . '/com_demo/banners/a.txt'))->toBeTrue()
        ->and($disk->url('banners/a.txt'))->toContain('/storage/media/com_demo/banners/a.txt');

    fileDispatcherDeleteDir($root);
});

it('allows public files without auth callback', function () {
    $dispatcher = new FileDispatcher();
    $file = new FileModel();
    $file->file_access = 'public';
    $file->app = 'com_demo';
    $file->user_id = 1;

    $method = new ReflectionMethod(FileDispatcher::class, 'authorize');
    $method->setAccessible(true);

    expect($method->invoke($dispatcher, $file))->toBeTrue();
});

it('denies private files for guests by default', function () {
    $dispatcher = new FileDispatcher();
    $file = new FileModel();
    $file->file_access = 'private';
    $file->app = 'com_demo';
    $file->user_id = 99;

    $method = new ReflectionMethod(FileDispatcher::class, 'authorize');
    $method->setAccessible(true);

    expect($method->invoke($dispatcher, $file))->toBeFalse();
});

it('uses package auth callback for private files', function () {
    FileDispatcher::authFor('com_demo', fn ($file, $user) => true);

    $dispatcher = new FileDispatcher();
    $file = new FileModel();
    $file->file_access = 'private';
    $file->app = 'com_demo';
    $file->user_id = 99;

    $method = new ReflectionMethod(FileDispatcher::class, 'authorize');
    $method->setAccessible(true);

    expect($method->invoke($dispatcher, $file))->toBeTrue();
});

it('streams an existing local file with cache headers', function () {
    $tmp = sys_get_temp_dir() . '/pinoox_file_dispatcher_' . uniqid('', true) . '.txt';
    file_put_contents($tmp, 'hello-dispatcher');

    $dispatcher = new FileDispatcher();
    $file = new FileModel();
    $file->file_access = 'public';
    $file->hash_id = str_repeat('ab', 16);
    $file->file_size = filesize($tmp);
    $file->file_realname = 'hello.txt';

    $method = new ReflectionMethod(FileDispatcher::class, 'streamLocalPath');
    $method->setAccessible(true);
    /** @var Response|BinaryFileResponse $response */
    $response = $method->invoke($dispatcher, $tmp, $file);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('Cache-Control'))->toContain('public');

    $etag = $response->headers->get('ETag') ?: (method_exists($response, 'getEtag') ? $response->getEtag() : null);
    expect($etag)->not->toBeEmpty();

    @unlink($tmp);
});

it('builds dispatcher urls from hash_id', function () {
    $file = new FileModel();
    $file->hash_id = str_repeat('cd', 16);

    $url = FileStorage::dispatcherUrl($file);
    $thumb = FileStorage::dispatcherUrl($file, true);

    expect($url)->toContain('/file/' . $file->hash_id)
        ->and($thumb)->toContain('/file/' . $file->hash_id . '/thumb');
});

it('normalizes custom dispatcher path prefixes', function () {
    expect(FileConfig::normalizeDispatcherPath('direct'))->toBe('direct')
        ->and(FileConfig::normalizeDispatcherPath('/link/to/'))->toBe('link/to')
        ->and(FileConfig::normalizeDispatcherPath('link//to'))->toBe('link/to')
        ->and(FileConfig::normalizeDispatcherPath(''))->toBe('file')
        ->and(FileConfig::normalizeDispatcherPath('../evil'))->toBe('file')
        ->and(FileConfig::buildDispatcherPath('direct', 'abc123'))->toBe('/direct/abc123')
        ->and(FileConfig::buildDispatcherPath('link/to', 'abc123', true))->toBe('/link/to/abc123/thumb');
});

it('builds dispatcher urls with an explicit path prefix helper', function () {
    expect(FileConfig::buildDispatcherPath('file', 'xyz'))->toBe('/file/xyz');
});

it('maps public()/private() to disks and syncs internal access', function () {
    $public = File::upload('avatar')->public();
    $private = File::upload('doc')->private();
    $override = File::upload('doc')->disk('s3');

    $diskProp = new ReflectionProperty($public, 'disk');
    $diskProp->setAccessible(true);
    $accessProp = new ReflectionProperty($public, 'access');
    $accessProp->setAccessible(true);

    expect($diskProp->getValue($public))->toBe('public')
        ->and($accessProp->getValue($public))->toBe('public')
        ->and($diskProp->getValue($private))->toBe('local')
        ->and($accessProp->getValue($private))->toBe('private')
        ->and($diskProp->getValue($override))->toBe('s3')
        ->and($accessProp->getValue($override))->toBe('private');
});

it('keeps access override on an explicit private disk for shared links', function () {
    $shared = File::upload('doc')->disk('local')->access('public');

    $diskProp = new ReflectionProperty($shared, 'disk');
    $diskProp->setAccessible(true);
    $accessProp = new ReflectionProperty($shared, 'access');
    $accessProp->setAccessible(true);

    expect($diskProp->getValue($shared))->toBe('local')
        ->and($accessProp->getValue($shared))->toBe('public');
});

it('evaluates login and callback policies', function () {
    $file = new FileModel();
    $file->file_access = 'private';
    $file->file_disk = 'local';
    $file->user_id = 1;

    expect(FilePolicy::evaluate(FileConfig::POLICY_PUBLIC, $file, null))->toBeTrue()
        ->and(FilePolicy::evaluate(FileConfig::POLICY_CALLBACK, $file, null))->toBeFalse()
        ->and(FilePolicy::evaluate(FileConfig::POLICY_LOGIN, $file, null))->toBeFalse()
        ->and(FilePolicy::evaluate(FileConfig::POLICY_OWNER, $file, null))->toBeFalse();
});

it('allows public disk files without auth', function () {
    $file = new FileModel();
    $file->file_access = 'private';
    $file->file_disk = 'public';
    $file->user_id = 1;

    expect(FilePolicy::allows($file, null))->toBeTrue();
});

it('denies private files when auth callback returns false', function () {
    $dispatcher = new FileDispatcher();
    $file = new FileModel();
    $file->file_access = 'private';
    $file->file_disk = 'local';
    $file->app = '__missing_package_for_policy__';
    $file->user_id = 1;

    FileDispatcher::authFor('__missing_package_for_policy__', fn () => false);

    $method = new ReflectionMethod(FileDispatcher::class, 'authorize');
    $method->setAccessible(true);

    expect($method->invoke($dispatcher, $file))->toBeFalse();
});

it('builds and accepts signed temporary URLs for private files', function () {
    $file = new FileModel();
    $file->hash_id = str_repeat('ef', 16);
    $file->file_access = 'private';
    $file->file_disk = 'local';
    $file->app = '__missing_package_for_policy__';
    $file->user_id = 1;

    $url = FileTemporaryUrl::make($file, 600);
    expect($url)->toContain('/file/' . $file->hash_id)
        ->and($url)->toContain('expires=')
        ->and($url)->toContain('signature=');

    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

    $prevExpires = $_GET['expires'] ?? null;
    $prevSignature = $_GET['signature'] ?? null;
    $_GET['expires'] = $query['expires'] ?? null;
    $_GET['signature'] = $query['signature'] ?? null;

    $dispatcher = new FileDispatcher();
    $method = new ReflectionMethod(FileDispatcher::class, 'authorize');
    $method->setAccessible(true);

    expect($method->invoke($dispatcher, $file))->toBeTrue()
        ->and(FileTemporaryUrl::isValid($file->hash_id, $query['expires'] ?? null, $query['signature'] ?? null))->toBeTrue()
        ->and(FileTemporaryUrl::isValid($file->hash_id, time() - 10, $query['signature'] ?? null))->toBeFalse();

    if ($prevExpires === null) {
        unset($_GET['expires']);
    } else {
        $_GET['expires'] = $prevExpires;
    }
    if ($prevSignature === null) {
        unset($_GET['signature']);
    } else {
        $_GET['signature'] = $prevSignature;
    }
});

class FileDispatcherArrayConfig implements ConfigInterface
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

function fileDispatcherDeleteDir(string $dir): void
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
