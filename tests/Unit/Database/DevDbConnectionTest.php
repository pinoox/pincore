<?php

use Pinoox\Component\Database\Connections\DevDbConnection;
use Pinoox\Component\Database\DatabaseConfig;
use Pinoox\Component\Kernel\Loader;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\App\AppProvider;
use Pinoox\Portal\Database\DB;
use Pinoox\Terminal\DevDB\DevDbClearCommand;
use Pinoox\Terminal\DevDB\DevDbInspectCommand;
use Pinoox\Terminal\DevDB\DevDbStatusCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function () {
    Loader::setBasePath(testProjectRoot());
    AppProvider::___();
    deleteTestApp('com_test_devdb');
    AppEngine::__rebuild();

    if (!class_exists('App\com_test_devdb\Model\DevUserModel')) {
        eval('namespace App\com_test_devdb\Model; class DevUserModel extends \Pinoox\Component\Database\Model { protected $table = "users"; protected $primaryKey = "user_id"; public $timestamps = false; protected $guarded = []; public function profile() { return $this->hasOne(ProfileModel::class, "user_id", "user_id"); } public function posts() { return $this->hasMany(PostModel::class, "user_id", "user_id"); } }');
    }

    if (!class_exists('App\com_test_devdb\Model\ProfileModel')) {
        eval('namespace App\com_test_devdb\Model; class ProfileModel extends \Pinoox\Component\Database\Model { protected $table = "profiles"; protected $primaryKey = "profile_id"; public $timestamps = false; protected $guarded = []; public function user() { return $this->belongsTo(DevUserModel::class, "user_id", "user_id"); } }');
    }

    if (!class_exists('App\com_test_devdb\Model\PostModel')) {
        eval('namespace App\com_test_devdb\Model; class PostModel extends \Pinoox\Component\Database\Model { protected $table = "posts"; protected $primaryKey = "post_id"; public $timestamps = false; protected $guarded = []; public function user() { return $this->belongsTo(DevUserModel::class, "user_id", "user_id"); } }');
    }

    if (!class_exists('App\com_test_devdb\database\factories\PostFactory')) {
        eval('namespace App\com_test_devdb\database\factories; class PostFactory extends \Pinoox\Component\Database\Factories\Factory { protected ?string $model = \App\com_test_devdb\Model\PostModel::class; public function definition(): array { return ["title" => "Factory Post", "status" => "draft"]; } }');
    }
});

afterEach(function () {
    deleteTestApp('com_test_devdb');
    AppEngine::__rebuild();
});

it('stores schema metadata and supports common CRUD queries', function () {
    $path = sys_get_temp_dir() . '/pinoox_devdb_test_' . uniqid();
    $connection = new DevDbConnection(null, 'devdb', '', ['path' => $path]);

    $connection->getSchemaBuilder()->create('posts', function ($table) {
        $table->increments('id');
        $table->string('title');
        $table->string('status')->nullable();
    });

    $id = $connection->table('posts')->insertGetId([
        'title' => 'Hello',
        'status' => 'draft',
    ]);

    $connection->table('posts')->where('id', $id)->update(['status' => 'published']);

    $row = $connection->table('posts')
        ->where('status', 'published')
        ->whereIn('id', [$id])
        ->orderBy('id', 'desc')
        ->first();

    expect($id)->toBe(1)
        ->and($row->title)->toBe('Hello')
        ->and($row->status)->toBe('published')
        ->and($connection->table('posts')->whereNull('missing')->exists())->toBeTrue()
        ->and($connection->table('posts')->count())->toBe(1)
        ->and(is_file($path . '/schema.json'))->toBeTrue()
        ->and(is_file($path . '/data/posts.json'))->toBeTrue()
        ->and(is_file($path . '/meta/sequences.json'))->toBeTrue();

    expect($connection->table('posts')->where('id', $id)->delete())->toBe(1)
        ->and($connection->table('posts')->exists())->toBeFalse();
});

it('supports Pinoox models, DB app tables, relations, pagination, and factories', function () {
    $path = sys_get_temp_dir() . '/pinoox_devdb_model_' . uniqid();
    $_ENV['APP_ENV'] = 'local';
    $_SERVER['APP_ENV'] = 'local';
    putenv('APP_ENV=local');
    \Pinoox\Support\SystemConfig::clearCache();

    writeTestApp('com_test_devdb', [
        'database' => [
            'driver' => 'devdb',
            'database' => 'devdb',
            'path' => $path,
            'prefix' => '',
        ],
    ]);
    AppEngine::__rebuild();

    DB::refreshCoreConnection([
        'driver' => 'devdb',
        'database' => 'devdb',
        'path' => $path,
        'prefix' => '',
    ]);

    $connection = DB::app('com_test_devdb');
    $connection->getSchemaBuilder()->create('users', function ($table) {
        $table->increments('user_id');
        $table->string('username')->unique();
    });
    $connection->getSchemaBuilder()->create('profiles', function ($table) {
        $table->increments('profile_id');
        $table->integer('user_id')->index();
        $table->string('display_name');
    });
    $connection->getSchemaBuilder()->create('posts', function ($table) {
        $table->increments('post_id');
        $table->integer('user_id')->index();
        $table->string('title');
        $table->string('status')->nullable()->index();
        $table->timestamps();
    });

    $user = App\com_test_devdb\Model\DevUserModel::create(['username' => 'ava']);
    $profile = App\com_test_devdb\Model\ProfileModel::create([
        'user_id' => $user->user_id,
        'display_name' => 'Ava Dev',
    ]);
    App\com_test_devdb\Model\PostModel::factory()
        ->count(3)
        ->sequence(['status' => 'draft'], ['status' => 'published'])
        ->create(['user_id' => $user->user_id]);

    DB::app('com_test_devdb')->table('posts')
        ->where('status', 'draft')
        ->update(['status' => 'published']);

    $published = App\com_test_devdb\Model\PostModel::where('status', 'published')
        ->orderBy('post_id')
        ->paginate(2);
    $firstPost = App\com_test_devdb\Model\PostModel::find(1);
    $loadedUser = App\com_test_devdb\Model\DevUserModel::with(['profile', 'posts'])->first();
    $loadedProfile = App\com_test_devdb\Model\ProfileModel::with('user')->first();

    expect($user->user_id)->toBe(1)
        ->and($profile->profile_id)->toBe(1)
        ->and($published->total())->toBe(3)
        ->and($published->items())->toHaveCount(2)
        ->and($firstPost->title)->toBe('Factory Post')
        ->and($loadedUser->profile->display_name)->toBe('Ava Dev')
        ->and($loadedUser->posts)->toHaveCount(3)
        ->and($loadedProfile->user->username)->toBe('ava')
        ->and(DB::app('com_test_devdb')->table('posts')->whereNull('deleted_at')->exists())->toBeTrue();

    $firstPost->delete();

    expect(App\com_test_devdb\Model\PostModel::count())->toBe(2)
        ->and(is_file($path . '/meta/indexes.json'))->toBeTrue();
});

it('resolves auto to DevDB only in local mode and rejects production fallback', function () {
    $previous = [
        'APP_ENV' => getenv('APP_ENV'),
        'DB_CONNECTION' => getenv('DB_CONNECTION'),
        'DB_DRIVER' => getenv('DB_DRIVER'),
        'DB_HOST' => getenv('DB_HOST'),
        'DB_DATABASE' => getenv('DB_DATABASE'),
        'DB_USERNAME' => getenv('DB_USERNAME'),
    ];

    $_ENV['APP_ENV'] = 'local';
    $_SERVER['APP_ENV'] = 'local';
    putenv('APP_ENV=local');
    $_ENV['DB_CONNECTION'] = 'auto';
    $_SERVER['DB_CONNECTION'] = 'auto';
    putenv('DB_CONNECTION=auto');
    $_ENV['DB_DRIVER'] = 'mysql';
    $_SERVER['DB_DRIVER'] = 'mysql';
    putenv('DB_DRIVER=mysql');
    $_ENV['DB_HOST'] = '127.0.0.254';
    $_SERVER['DB_HOST'] = '127.0.0.254';
    putenv('DB_HOST=127.0.0.254');
    $_ENV['DB_DATABASE'] = sys_get_temp_dir() . '/missing-devdb.sqlite';
    $_SERVER['DB_DATABASE'] = $_ENV['DB_DATABASE'];
    putenv('DB_DATABASE=' . $_ENV['DB_DATABASE']);
    $_ENV['DB_USERNAME'] = 'missing';
    $_SERVER['DB_USERNAME'] = 'missing';
    putenv('DB_USERNAME=missing');
    \Pinoox\Support\SystemConfig::clearCache();

    expect(DatabaseConfig::connectionName())->toBe('devdb');

    $_ENV['APP_ENV'] = 'production';
    $_SERVER['APP_ENV'] = 'production';
    putenv('APP_ENV=production');
    \Pinoox\Support\SystemConfig::clearCache();

    expect(fn () => DatabaseConfig::connectionName())->toThrow(RuntimeException::class);

    foreach ($previous as $key => $value) {
        if ($value === false) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
            continue;
        }

        $_ENV[$key] = (string) $value;
        $_SERVER[$key] = (string) $value;
        putenv($key . '=' . $value);
    }
    \Pinoox\Support\SystemConfig::clearCache();
});

it('exposes DevDB status, inspect, and clear commands', function () {
    $path = sys_get_temp_dir() . '/pinoox_devdb_cli_' . uniqid();
    $_ENV['DEVDB_PATH'] = $path;
    $_SERVER['DEVDB_PATH'] = $path;
    putenv('DEVDB_PATH=' . $path);
    \Pinoox\Support\SystemConfig::clearCache();

    $connection = new DevDbConnection(null, 'devdb', '', ['path' => $path]);
    $connection->getSchemaBuilder()->create('notes', function ($table) {
        $table->increments('id');
        $table->string('body');
    });
    $connection->table('notes')->insertGetId(['body' => 'CLI']);

    $application = new Application();
    $application->add(new DevDbStatusCommand());
    $application->add(new DevDbInspectCommand());
    $application->add(new DevDbClearCommand());

    $status = new CommandTester($application->find('devdb:status'));
    $status->execute(['--json' => true]);
    $statusData = json_decode($status->getDisplay(), true);

    $inspect = new CommandTester($application->find('devdb:inspect'));
    $inspect->execute(['table' => 'notes', '--json' => true]);
    $inspectData = json_decode($inspect->getDisplay(), true);

    $clear = new CommandTester($application->find('devdb:clear'));
    $clear->execute(['--force' => true]);

    expect($statusData['table_count'])->toBe(1)
        ->and($inspectData['row_count'])->toBe(1)
        ->and($inspectData['rows'][0]['body'])->toBe('CLI')
        ->and((new \Pinoox\Component\Database\DevDB\DevDbStore($path))->status()['table_count'])->toBe(0);
});

it('reports unsupported advanced queries with a clear DevDB error', function () {
    $connection = new DevDbConnection(null, 'devdb', '', [
        'path' => sys_get_temp_dir() . '/pinoox_devdb_unsupported_' . uniqid(),
    ]);
    $connection->getSchemaBuilder()->create('posts', function ($table) {
        $table->increments('id');
    });

    expect(fn () => $connection->table('posts')->join('users', 'users.id', '=', 'posts.user_id')->get())
        ->toThrow(\Pinoox\Component\Database\DevDB\DevDbException::class, 'joins on table "posts"');
});
