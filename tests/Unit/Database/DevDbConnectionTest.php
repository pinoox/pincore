<?php

use Pinoox\Component\Database\Connections\DevDbConnection;
use Pinoox\Component\Database\DatabaseConfig;
use Pinoox\Component\Database\DatabaseManager;
use Pinoox\Component\Database\DevDB\DevDbRuntime;
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
    $_ENV['DEVDB_ENGINE'] = 'json';
    $_SERVER['DEVDB_ENGINE'] = 'json';
    putenv('DEVDB_ENGINE=json');
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
    $_ENV['DEVDB_ENGINE'] = 'json';
    $_SERVER['DEVDB_ENGINE'] = 'json';
    putenv('DEVDB_ENGINE=json');
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

    expect(fn () => $connection->select('select * from posts'))
        ->toThrow(\Pinoox\Component\Database\DevDB\DevDbException::class, 'raw select SQL');
});

it('supports DevDB v2 joins, advanced aggregates, grouped rows, nested OR queries, and transactions', function () {
    $connection = new DevDbConnection(null, 'devdb', '', [
        'path' => sys_get_temp_dir() . '/pinoox_devdb_v2_' . uniqid(),
    ]);

    $connection->getSchemaBuilder()->create('users', function ($table) {
        $table->increments('id');
        $table->string('name');
        $table->string('role');
    });
    $connection->getSchemaBuilder()->create('posts', function ($table) {
        $table->increments('id');
        $table->integer('user_id');
        $table->string('status');
        $table->integer('views');
    });

    $connection->table('users')->insert([
        ['id' => 1, 'name' => 'Ava', 'role' => 'author'],
        ['id' => 2, 'name' => 'Noah', 'role' => 'editor'],
        ['id' => 3, 'name' => 'Mina', 'role' => 'author'],
    ]);
    $connection->table('posts')->insert([
        ['id' => 1, 'user_id' => 1, 'status' => 'published', 'views' => 10],
        ['id' => 2, 'user_id' => 1, 'status' => 'draft', 'views' => 5],
        ['id' => 3, 'user_id' => 2, 'status' => 'published', 'views' => 30],
    ]);

    $joined = $connection->table('posts')
        ->join('users', 'users.id', '=', 'posts.user_id')
        ->where('users.role', 'author')
        ->orderBy('posts.id')
        ->get(['posts.id as post_id', 'users.name as author']);

    $leftJoined = $connection->table('users')
        ->leftJoin('posts', 'posts.user_id', '=', 'users.id')
        ->where('users.name', 'Mina')
        ->first(['users.name']);

    $grouped = $connection->table('posts')
        ->groupBy('status')
        ->having('aggregate_count', '>=', 1)
        ->orderBy('status')
        ->get(['status', 'aggregate_count', 'sum_views', 'avg_views', 'max_views']);

    $nested = $connection->table('posts')
        ->where(function ($query) {
            $query->where('status', 'draft')->orWhere('views', '>', 20);
        })
        ->whereBetween('views', [1, 30])
        ->orderBy('id')
        ->pluck('id')
        ->all();

    expect($joined->pluck('author')->all())->toBe(['Ava', 'Ava'])
        ->and($leftJoined->name)->toBe('Mina')
        ->and($connection->table('posts')->lockForUpdate()->where('id', 1)->first()->views)->toBe(10)
        ->and($connection->table('posts')->sum('views'))->toBe(45.0)
        ->and($connection->table('posts')->avg('views'))->toBe(15.0)
        ->and($connection->table('posts')->min('views'))->toBe(5)
        ->and($connection->table('posts')->max('views'))->toBe(30)
        ->and($grouped->firstWhere('status', 'published')->aggregate_count)->toBe(2)
        ->and($grouped->firstWhere('status', 'published')->sum_views)->toBe(40)
        ->and($nested)->toBe([2, 3]);

    $connection->beginTransaction();
    $connection->table('posts')->insert(['id' => 4, 'user_id' => 3, 'status' => 'draft', 'views' => 99]);
    expect($connection->table('posts')->count())->toBe(4);
    $connection->rollBack();
    expect($connection->table('posts')->count())->toBe(3);

    $connection->beginTransaction();
    $connection->table('posts')->insert(['id' => 4, 'user_id' => 3, 'status' => 'draft', 'views' => 99]);
    $connection->commit();
    expect($connection->table('posts')->count())->toBe(4);
});

it('uses an internal SQLite DevDB engine automatically when pdo sqlite is available', function () {
    if (!extension_loaded('pdo_sqlite')) {
        expect(true)->toBeTrue();

        return;
    }

    $previous = [
        'APP_ENV' => getenv('APP_ENV'),
        'DB_CONNECTION' => getenv('DB_CONNECTION'),
        'DEVDB_ENGINE' => getenv('DEVDB_ENGINE'),
        'DEVDB_PATH' => getenv('DEVDB_PATH'),
    ];
    $path = sys_get_temp_dir() . '/pinoox_devdb_sqlite_' . uniqid();

    $_ENV['APP_ENV'] = 'local';
    $_SERVER['APP_ENV'] = 'local';
    putenv('APP_ENV=local');
    $_ENV['DB_CONNECTION'] = 'devdb';
    $_SERVER['DB_CONNECTION'] = 'devdb';
    putenv('DB_CONNECTION=devdb');
    $_ENV['DEVDB_ENGINE'] = 'auto';
    $_SERVER['DEVDB_ENGINE'] = 'auto';
    putenv('DEVDB_ENGINE=auto');
    $_ENV['DEVDB_PATH'] = $path;
    $_SERVER['DEVDB_PATH'] = $path;
    putenv('DEVDB_PATH=' . $path);
    \Pinoox\Support\SystemConfig::clearCache();

    $root = DatabaseConfig::normalize([
        'connections' => [
            'devdb' => [
                'driver' => 'devdb',
                'path' => $path,
                'prefix' => '',
            ],
        ],
    ]);
    $config = DatabaseConfig::connectionConfig($root, 'devdb');

    expect($config['driver'])->toBe('sqlite')
        ->and($config['devdb_engine'])->toBe('sqlite')
        ->and($config['database'])->toBe(str_replace('\\', '/', $path) . '/devdb.sqlite');

    $manager = new DatabaseManager(new Illuminate\Container\Container());
    $manager->addConnection($config, 'devdb_probe');
    $connection = $manager->getConnection('devdb_probe');
    $connection->getSchemaBuilder()->create('posts', function ($table) {
        $table->increments('id');
        $table->string('status');
        $table->integer('views');
    });
    $connection->table('posts')->insert([
        ['status' => 'published', 'views' => 10],
        ['status' => 'draft', 'views' => 5],
    ]);

    $raw = $connection->select('select status, sum(views) as total from posts group by status order by status');
    $runtime = new DevDbRuntime();
    $status = $runtime->status();
    $inspect = $runtime->inspectTable('posts', 5);

    expect($raw[0]->status ?? $raw[0]['status'] ?? null)->toBe('draft')
        ->and($status['engine'])->toBe('sqlite')
        ->and($status['table_count'])->toBe(1)
        ->and($inspect['row_count'])->toBe(2);

    $runtime->clear();
    expect($runtime->status()['table_count'])->toBe(0);

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

it('keeps the JSON DevDB engine available when requested explicitly', function () {
    $previous = [
        'APP_ENV' => getenv('APP_ENV'),
        'DEVDB_ENGINE' => getenv('DEVDB_ENGINE'),
    ];
    $_ENV['APP_ENV'] = 'local';
    $_SERVER['APP_ENV'] = 'local';
    putenv('APP_ENV=local');
    $_ENV['DEVDB_ENGINE'] = 'json';
    $_SERVER['DEVDB_ENGINE'] = 'json';
    putenv('DEVDB_ENGINE=json');
    \Pinoox\Support\SystemConfig::clearCache();

    $config = DatabaseConfig::normalizeConnectionDriver([
        'driver' => 'devdb',
        'path' => sys_get_temp_dir() . '/pinoox_devdb_json_' . uniqid(),
        'prefix' => '',
    ]);

    expect($config['driver'])->toBe('devdb')
        ->and($config['devdb_engine'])->toBe('json');

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
