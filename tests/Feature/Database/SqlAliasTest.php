<?php

use Pinoox\Component\Database\DatabaseManager;
use Pinoox\Component\Kernel\Loader;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\App\AppProvider;

beforeEach(function () {
    Loader::setBasePath(testProjectRoot());
    AppProvider::___();
    deleteTestApp('com_test_sql_alias');
    AppEngine::__rebuild();
});

afterEach(function () {
    deleteTestApp('com_test_sql_alias');
    AppEngine::__rebuild();
});

it('keeps sql aliases short while prefixing table names only', function () {
    $manager = new DatabaseManager(new Illuminate\Container\Container());
    $manager->registerCoreConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'pinx_',
    ]);

    writeTestApp('com_test_sql_alias', [
        'database' => null,
        'table' => [
            'prefix' => 'paper_',
        ],
    ]);
    AppEngine::__rebuild();

    expect($manager->sqlAlias('p', 'com_test_sql_alias'))->toBe('p')
        ->and($manager->sqlCol('p', 'post_id', 'com_test_sql_alias'))->toBe('p.post_id')
        ->and($manager->sqlCol('t', 'term_id'))->toBe('t.term_id');
});

it('compiles from and raw clauses with short aliases under a prefixed connection', function () {
    $manager = new DatabaseManager(new Illuminate\Container\Container());
    $manager->registerCoreConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'pinx_',
    ]);

    writeTestApp('com_test_sql_alias', [
        'database' => null,
        'table' => [
            'prefix' => 'paper_',
        ],
    ]);
    AppEngine::__rebuild();

    $sql = $manager->app('com_test_sql_alias')
        ->table('post', 'p')
        ->join('term as t', 'p.post_id', '=', 't.post_id')
        ->selectRaw('p.post_id, COUNT(t.term_id) AS cnt')
        ->groupByRaw('p.post_id')
        ->toSql();

    expect($sql)
        ->toContain('"paper_post" as "p"')
        ->toContain('"paper_term" as "t"')
        ->toContain('p.post_id')
        ->toContain('t.term_id')
        ->not->toContain('"paper_p"')
        ->not->toContain('"paper_t"');
});

it('prefixes logical table names through tableName but not sql aliases', function () {
    $manager = new DatabaseManager(new Illuminate\Container\Container());
    $manager->registerCoreConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'pinx_',
    ]);

    writeTestApp('com_test_sql_alias', [
        'database' => null,
        'table' => [
            'prefix' => 'paper_',
        ],
    ]);
    AppEngine::__rebuild();

    expect($manager->tableName('post', 'com_test_sql_alias'))->toBe('post')
        ->and($manager->physicalTableName('post', 'com_test_sql_alias'))->toBe('paper_post')
        ->and($manager->tableName('post as p', 'com_test_sql_alias'))->toBe('post AS p');
});
