<?php

use Pinoox\Component\Database\DatabaseManager;
use Pinoox\Component\Kernel\Loader;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\App\AppProvider;
use Pinoox\Portal\Database\DB;

beforeEach(function () {
    Loader::setBasePath(testProjectRoot());
    AppProvider::___();
    foreach ([
        'com_test_model_query',
        'com_test_model_create',
        'com_test_model_platform',
    ] as $package) {
        deleteTestApp($package);
    }
    AppEngine::__rebuild();

    try {
        DB::___()->flushPackageConnections();
    } catch (\Throwable) {
    }

    if (!class_exists('App\com_test_model_query\Model\ItemModel')) {
        eval('namespace App\com_test_model_query\Model; class ItemModel extends \Pinoox\Component\Database\Model { protected $table = "items"; public $timestamps = true; protected $guarded = []; }');
    }
    if (!class_exists('App\com_test_model_create\Model\ItemModel')) {
        eval('namespace App\com_test_model_create\Model; class ItemModel extends \Pinoox\Component\Database\Model { protected $table = "items"; public $timestamps = true; protected $guarded = []; }');
    }
    if (!class_exists('App\com_test_model_platform\Model\PlatformItemModel')) {
        eval('namespace App\com_test_model_platform\Model; class PlatformItemModel extends \Pinoox\Component\Database\Model { protected $connection = "platform"; protected $table = "items"; public $timestamps = false; protected $guarded = []; }');
    }
});

afterEach(function () {
    foreach ([
        'com_test_model_query',
        'com_test_model_create',
        'com_test_model_platform',
    ] as $package) {
        deleteTestApp($package);
    }
    AppEngine::__rebuild();
});

it('strips logical and physical table prefixes from qualified columns', function () {
    writeTestApp('com_test_model_query', [
        'database' => null,
        'table' => [
            'prefix' => 'shop_',
        ],
    ]);
    AppEngine::__rebuild();

    $model = new App\com_test_model_query\Model\ItemModel();

    expect($model->qualifyColumn('items.id'))->toBe('id')
        ->and($model->qualifyColumn('shop_items.id'))->toBe('id')
        ->and($model->qualifyColumn('other.id'))->toBe('other.id');
});

it('supports bulk update with timestamps on prefixed app tables', function () {
    writeTestApp('com_test_model_query', [
        'database' => null,
        'table' => [
            'prefix' => 'shop_',
        ],
    ]);
    AppEngine::__rebuild();

    DB::refreshCoreConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'pinx_',
    ]);

    $schema = DB::app('com_test_model_query')->getSchemaBuilder();
    $schema->create('items', function ($table) {
        $table->increments('id');
        $table->string('name')->nullable();
        $table->timestamps();
    });

    App\com_test_model_query\Model\ItemModel::query()->insert([
        'name' => 'first',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $updated = App\com_test_model_query\Model\ItemModel::query()->update(['name' => 'second']);

    expect($updated)->toBe(1)
        ->and(App\com_test_model_query\Model\ItemModel::query()->value('name'))->toBe('second');
});

it('keeps prefix-only app models on the app connection for create and query', function () {
    writeTestApp('com_test_model_create', [
        'database' => null,
        'table' => [
            'prefix' => 'app_',
        ],
    ]);
    AppEngine::__rebuild();

    DB::refreshCoreConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'pinx_',
    ]);

    $appConnection = DB::app('com_test_model_create');
    $appConnection->getSchemaBuilder()->create('items', function ($table) {
        $table->increments('id');
        $table->string('name')->nullable();
        $table->timestamps();
    });

    $model = new App\com_test_model_create\Model\ItemModel();

    expect($model->getConnectionName())->toBe('app_com_test_model_create_default')
        ->and($appConnection->getName())->toBe('app_com_test_model_create_default')
        ->and($appConnection->getTablePrefix())->toBe('app_')
        ->and(DB::core()->getTablePrefix())->toBe('pinx_')
        ->and($model->newQuery()->toSql())->toContain('app_items')
        ->and($model->newQuery()->toSql())->not->toContain('pinx_items');

    // Eloquent create() copies Connection::getName() onto the instance — a wrong
    // cloned name (platform) previously rebound inserts onto pinx_*.
    $created = App\com_test_model_create\Model\ItemModel::create(['name' => 'otp-row']);

    expect($created->getConnectionName())->toBe('app_com_test_model_create_default')
        ->and($created->getConnection()->getName())->toBe('app_com_test_model_create_default')
        ->and($created->getConnection()->getTablePrefix())->toBe('app_')
        ->and(App\com_test_model_create\Model\ItemModel::query()->value('name'))->toBe('otp-row');
});

it('honors an explicit platform connection on an app model', function () {
    writeTestApp('com_test_model_platform', [
        'database' => null,
        'table' => [
            'prefix' => 'app_',
        ],
    ]);
    AppEngine::__rebuild();

    DB::refreshCoreConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'pinx_',
    ]);

    DB::core()->getSchemaBuilder()->create('items', function ($table) {
        $table->increments('id');
        $table->string('name')->nullable();
    });

    $model = new App\com_test_model_platform\Model\PlatformItemModel();

    expect($model->getConnectionName())->toBe('platform')
        ->and($model->getConnection()->getTablePrefix())->toBe('pinx_')
        ->and($model->newQuery()->toSql())->toContain('pinx_items')
        ->and($model->newQuery()->toSql())->not->toContain('app_items');
});
