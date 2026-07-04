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
    $path = testDevDbPath('crud');
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
    $path = testDevDbPath('models');
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
    restoreTestDevDbEnvironment();
    \Pinoox\Support\SystemConfig::clearCache();
});

it('exposes DevDB status, inspect, and clear commands', function () {
    $path = testDevDbPath('cli');
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

it('translates common raw SQL into DevDB JSON operations', function () {
    $connection = new DevDbConnection(null, 'devdb', '', [
        'path' => testDevDbPath('raw_sql'),
    ]);
    $connection->getSchemaBuilder()->create('posts', function ($table) {
        $table->increments('id');
        $table->string('title');
        $table->string('status')->nullable();
        $table->integer('views');
    });

    expect($connection->insert(
        'insert into posts (title, status, views) values (?, ?, ?), (?, ?, ?)',
        ['First', 'draft', 10, 'Second', 'published', 25],
    ))->toBeTrue();

    $published = $connection->select(
        'select id, title as headline from posts where status = ? and views >= ? order by views desc limit 1',
        ['published', 20],
    );

    $count = $connection->selectOne('select count(*) as total, sum(views) as views from posts where id in (?, ?)', [1, 2]);
    $updated = $connection->affectingStatement('update posts set status = ? where title like ?', ['published', 'First%']);
    $deleted = $connection->affectingStatement('delete from posts where views < ?', [20]);

    expect($published)->toHaveCount(1)
        ->and($published[0]->headline)->toBe('Second')
        ->and($count->total)->toBe(2)
        ->and($count->views)->toBe(35.0)
        ->and($updated)->toBe(1)
        ->and($deleted)->toBe(1)
        ->and($connection->selectOne('select * from posts where id = ?', [2])->title)->toBe('Second')
        ->and(fn () => $connection->select('select * from missing_posts'))
        ->toThrow(\Pinoox\Component\Database\DevDB\DevDbException::class, 'does not exist');
});

it('translates advanced raw SQL joins, boolean clauses, grouping, and aliases', function () {
    $connection = new DevDbConnection(null, 'devdb', '', [
        'path' => testDevDbPath('raw_sql_advanced'),
    ]);
    $connection->getSchemaBuilder()->create('users', function ($table) {
        $table->increments('id');
        $table->string('name');
        $table->string('role');
    });
    $connection->getSchemaBuilder()->create('posts', function ($table) {
        $table->increments('id');
        $table->integer('user_id');
        $table->string('title');
        $table->string('status')->nullable();
        $table->integer('views');
    });
    $connection->getSchemaBuilder()->create('comments', function ($table) {
        $table->increments('id');
        $table->integer('post_id');
        $table->string('body');
    });

    $connection->insert(
        'insert into users (id, name, role) values (?, ?, ?), (?, ?, ?), (?, ?, ?)',
        [1, 'Ava', 'author', 2, 'Noah', 'editor', 3, 'Mina', 'author'],
    );
    $connection->insert(
        'insert into posts (id, user_id, title, status, views) values (?, ?, ?, ?, ?), (?, ?, ?, ?, ?), (?, ?, ?, ?, ?), (?, ?, ?, ?, ?)',
        [
            1, 1, 'Intro', 'published', 10,
            2, 1, 'Draft', 'draft', 5,
            3, 2, 'Review', 'published', 30,
            4, 3, 'Quiet', null, 1,
        ],
    );
    $connection->insert(
        'insert into comments (post_id, body) values (?, ?), (?, ?), (?, ?)',
        [1, 'Nice', 1, 'Great', 3, 'Approved'],
    );

    $joined = $connection->select(
        'select p.id as post_id, p.title, u.name as author from posts as p join users as u on u.id = p.user_id where (p.status = ? or p.views >= ?) and u.role <> ? order by p.views desc limit 3',
        ['draft', 20, 'guest'],
    );
    $leftJoined = $connection->select(
        'select p.title, c.body as comment_body from posts p left join comments c on c.post_id = p.id where p.id in (?, ?) order by p.id asc, c.id asc',
        [2, 4],
    );
    $grouped = $connection->select(
        'select u.role, count(*) as total_posts, sum(p.views) as total_views, max(p.views) as max_views from posts p join users u on u.id = p.user_id group by u.role having total_posts >= ? order by total_views desc',
        [1],
    );
    $boolean = $connection->select(
        'select id, title from posts where views between ? and ? and status not in (?, ?) and title not like ? order by id',
        [1, 30, 'draft', 'archived', 'Quiet%'],
    );

    expect(array_map(fn ($row) => $row->post_id, $joined))->toBe([3, 2])
        ->and($joined[0]->author)->toBe('Noah')
        ->and($leftJoined)->toHaveCount(2)
        ->and($leftJoined[0]->comment_body)->toBeNull()
        ->and($grouped[0]->role)->toBe('editor')
        ->and($grouped[0]->total_posts)->toBe(1)
        ->and($grouped[0]->total_views)->toBe(30.0)
        ->and($grouped[1]->role)->toBe('author')
        ->and($grouped[1]->total_posts)->toBe(3)
        ->and($grouped[1]->max_views)->toBe(10)
        ->and(array_map(fn ($row) => $row->title, $boolean))->toBe(['Intro', 'Review']);

    $union = $connection->select('select id from posts union select id from users order by id');

    expect(array_map(fn ($row) => $row->id, $union))->toBe([1, 2, 3, 4]);
});

it('translates common raw SQL functions in select, where, group, and order clauses', function () {
    $connection = new DevDbConnection(null, 'devdb', '', [
        'path' => testDevDbPath('raw_sql_functions'),
    ]);
    $connection->getSchemaBuilder()->create('events', function ($table) {
        $table->increments('id');
        $table->string('title')->nullable();
        $table->string('email');
        $table->string('created_at');
        $table->integer('amount');
    });

    $connection->insert(
        'insert into events (title, email, created_at, amount) values (?, ?, ?, ?), (?, ?, ?, ?), (?, ?, ?, ?)',
        [
            ' Launch ', 'AVA@EXAMPLE.COM', '2026-06-29 10:15:00', 9,
            null, 'noah@example.com', '2026-06-29 13:45:00', -4,
            'Review', 'mina@example.com', '2026-06-30 08:00:00', 15,
        ],
    );

    $daily = $connection->select(
        "select date(created_at) as event_day, count(*) as total, sum(abs(amount)) as volume from events group by date(created_at) having total >= ? order by event_day",
        [1],
    );
    $friendly = $connection->selectOne(
        "select lower(email) as email, upper(trim(title)) as clean_title, coalesce(title, 'Untitled') as fallback, concat(substr(email, 1, 4), '-', year(created_at)) as token from events where date(created_at) = ? and lower(email) like ? order by length(email) desc",
        ['2026-06-29', 'ava%'],
    );
    $filtered = $connection->select(
        "select id from events where month(created_at) = ? and day(created_at) = ? and round(abs(amount), 0) >= ? order by id",
        [6, 29, 4],
    );

    expect($daily)->toHaveCount(2)
        ->and($daily[0]->event_day)->toBe('2026-06-29')
        ->and($daily[0]->total)->toBe(2)
        ->and($daily[0]->volume)->toBe(13.0)
        ->and($friendly->email)->toBe('ava@example.com')
        ->and($friendly->clean_title)->toBe('LAUNCH')
        ->and($friendly->fallback)->toBe(' Launch ')
        ->and($friendly->token)->toBe('AVA@-2026')
        ->and(array_map(fn ($row) => $row->id, $filtered))->toBe([1, 2]);
});

it('can run DevDB as a standalone component without Pinoox app bootstrap', function () {
    $database = \Pinoox\DevDB\DevDatabase::open(testDevDbPath('standalone'));
    $database->createTable('notes', [
        'id' => ['type' => 'integer', 'primary' => true, 'auto_increment' => true],
        'body' => 'string',
        'created_at' => 'string',
    ]);

    expect($database->statement(
        'insert into notes (body, created_at) values (?, ?), (?, ?)',
        ['First', '2026-06-29 10:00:00', 'Second', '2026-06-30 10:00:00'],
    ))->toBeTrue();

    $row = $database->selectOne(
        'select id, upper(body) as body, date(created_at) as day from notes where date(created_at) = ?',
        ['2026-06-30'],
    );

    expect($row->id)->toBe(2)
        ->and($row->body)->toBe('SECOND')
        ->and($row->day)->toBe('2026-06-30')
        ->and($database->execute('delete from notes where id = ?', [1]))->toBe(1);
});

it('translates raw SQL schema and introspection commands', function () {
    $connection = new DevDbConnection(null, 'devdb', '', [
        'path' => testDevDbPath('raw_schema'),
    ]);

    expect($connection->statement(
        'create table users (id integer primary key auto_increment, name varchar(120) not null, email varchar(190) default null, created_at datetime, unique key users_email_unique (email))',
    ))->toBeTrue();

    $connection->statement('alter table users add column status varchar(20) default "active"');
    $connection->statement('create index users_status_index on users (status)');
    $connection->insert(
        'insert into users (name, email, created_at) values (?, ?, ?)',
        ['Ava', 'ava@example.com', '2026-06-29 09:00:00'],
    );

    $tables = $connection->select('show tables');
    $columns = $connection->select('describe users');
    $indexes = $connection->select('show index from users');
    $row = $connection->selectOne('select id, name, status from users');

    expect(array_map(fn ($item) => $item->table, $tables))->toBe(['users'])
        ->and(array_map(fn ($item) => $item->Field, $columns))->toBe(['id', 'name', 'email', 'created_at', 'status'])
        ->and($columns[0]->Key)->toBe('PRI')
        ->and($columns[0]->Extra)->toBe('auto_increment')
        ->and($columns[1]->Null)->toBe('NO')
        ->and($row->id)->toBe(1)
        ->and($row->status)->toBe('active')
        ->and(array_map(fn ($item) => $item->Key_name, $indexes))->toContain('users_email_unique', 'users_status_index');

    $connection->statement('alter table users rename column status to state');
    expect(array_map(fn ($item) => $item->Field, $connection->select('desc users')))->toContain('state');

    $connection->statement('drop index users_status_index on users');
    expect(array_map(fn ($item) => $item->Key_name, $connection->select('show indexes from users')))->not->toContain('users_status_index');

    expect($connection->affectingStatement('drop table if exists users'))->toBe(1)
        ->and($connection->select('show tables'))->toBe([]);
});

it('accepts MySQL dump style setup, table options, enum columns, and BTREE indexes', function () {
    $connection = new DevDbConnection(null, 'devdb', '', [
        'path' => testDevDbPath('mysql_dump'),
    ]);

    expect($connection->statement('SET NAMES utf8mb4'))->toBeTrue()
        ->and($connection->statement('SET FOREIGN_KEY_CHECKS = 0'))->toBeTrue()
        ->and($connection->statement('DROP TABLE IF EXISTS `names`'))->toBeTrue();

    $connection->statement(<<<'SQL'
CREATE TABLE `names`  (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL,
  `age` int NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 5 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_bin ROW_FORMAT = Dynamic
SQL);

    $connection->statement(<<<'SQL'
CREATE TABLE `com_pindev_reservation_discount`  (
  `discount_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  `type` enum('percentage','fixed') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'percentage',
  `value` int(11) NOT NULL,
  `max_discount` int(11) NULL DEFAULT NULL,
  `max_usage` int(11) NULL DEFAULT NULL,
  `used_count` int(11) NOT NULL DEFAULT 0,
  `valid_from` date NULL DEFAULT NULL,
  `valid_until` date NULL DEFAULT NULL,
  `showtime_id` bigint(20) UNSIGNED NULL DEFAULT NULL,
  `theater_id` int(10) UNSIGNED NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`discount_id`) USING BTREE,
  UNIQUE INDEX `com_pindev_reservation_discount_code_unique`(`code`) USING BTREE,
  INDEX `com_pindev_reservation_discount_showtime_id_foreign`(`showtime_id`) USING BTREE,
  INDEX `com_pindev_reservation_discount_theater_id_foreign`(`theater_id`) USING BTREE,
  INDEX `com_pindev_reservation_discount_code_is_active_index`(`code`, `is_active`) USING BTREE,
  INDEX `com_pindev_reservation_discount_valid_from_valid_until_index`(`valid_from`, `valid_until`) USING BTREE,
  CONSTRAINT `com_pindev_reservation_discount_showtime_id_foreign` FOREIGN KEY (`showtime_id`) REFERENCES `com_pindev_reservation_showtime` (`showtime_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `com_pindev_reservation_discount_theater_id_foreign` FOREIGN KEY (`theater_id`) REFERENCES `com_pindev_reservation_theater` (`theater_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE = InnoDB AUTO_INCREMENT = 6 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_bin ROW_FORMAT = Dynamic
SQL);

    $connection->insert(
        'insert into names (name, age) values (?, ?)',
        ['Ava', 28],
    );
    $connection->insert(
        'insert into com_pindev_reservation_discount (code, title, value) values (?, ?, ?)',
        ['SUMMER', 'Summer discount', 15],
    );

    $names = $connection->select('describe names');
    $discountColumns = $connection->select('describe com_pindev_reservation_discount');
    $discountIndexes = $connection->select('show indexes from com_pindev_reservation_discount');
    $nameRow = $connection->selectOne('select id, name from names');
    $discountRow = $connection->selectOne('select discount_id, code from com_pindev_reservation_discount');

    expect($nameRow->id)->toBe(5)
        ->and($discountRow->discount_id)->toBe(6)
        ->and($names[0]->Key)->toBe('PRI')
        ->and($names[0]->Extra)->toBe('auto_increment')
        ->and(array_map(fn ($column) => $column->Field, $discountColumns))->toContain('type', 'is_active', 'valid_until')
        ->and($discountColumns[4]->Type)->toBe('enum')
        ->and(array_map(fn ($index) => $index->Key_name, $discountIndexes))->toContain(
            'com_pindev_reservation_discount_code_unique',
            'com_pindev_reservation_discount_code_is_active_index',
            'com_pindev_reservation_discount_valid_from_valid_until_index',
        );
});

it('supports DevDB v2 joins, advanced aggregates, grouped rows, nested OR queries, and transactions', function () {
    $connection = new DevDbConnection(null, 'devdb', '', [
        'path' => testDevDbPath('v2'),
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
    $path = testDevDbPath('sqlite_auto');

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

it('keeps DevDB SQLite compatible with framework migration probes', function () {
    if (!extension_loaded('pdo_sqlite')) {
        expect(true)->toBeTrue();

        return;
    }

    $manager = new DatabaseManager(new Illuminate\Container\Container());
    $manager->addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'devdb' => true,
        'devdb_engine' => 'sqlite',
    ], 'devdb_sqlite_probe');

    $connection = $manager->getConnection('devdb_sqlite_probe');
    $connection->getSchemaBuilder()->create('pinx_history', function ($table) {
        $table->increments('id');
        $table->string('migration')->nullable();
    });

    expect($connection->statement('SET FOREIGN_KEY_CHECKS=0'))->toBeTrue()
        ->and($connection->statement('SET FOREIGN_KEY_CHECKS=1'))->toBeTrue()
        ->and($connection->selectOne(
            'SELECT 1 AS found FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
            [':memory:', 'pinx_history'],
        )->found)->toBe(1)
        ->and($connection->selectOne(
            'SELECT 1 AS found FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
            [':memory:', 'missing_table'],
        ))->toBeNull();
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
        'path' => testDevDbPath('json_engine'),
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
