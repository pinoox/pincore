<?php

use Pinoox\Component\Database\DatabaseManager;
use Pinoox\Component\Kernel\Loader;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\App\AppProvider;
use Pinoox\Portal\Database\DB;

beforeEach(function () {
    Loader::setBasePath(testProjectRoot());
    AppProvider::___();
    deleteTestApp('com_test_model_query');
    AppEngine::__rebuild();

    if (!class_exists('App\com_test_model_query\Model\ItemModel')) {
        eval('namespace App\com_test_model_query\Model; class ItemModel extends \Pinoox\Component\Database\Model { protected $table = "items"; public $timestamps = true; protected $guarded = []; }');
    }
});

afterEach(function () {
    deleteTestApp('com_test_model_query');
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
    $manager = new DatabaseManager(new Illuminate\Container\Container());
    $manager->registerCoreConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'pinx_',
    ]);

    writeTestApp('com_test_model_query', [
        'database' => null,
        'table' => [
            'prefix' => 'shop_',
        ],
    ]);
    AppEngine::__rebuild();

    DB::registerCoreConnection([
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
