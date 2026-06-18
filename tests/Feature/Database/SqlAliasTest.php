<?php

use Pinoox\Component\Database\DatabaseManager;
use Pinoox\Component\Database\DatabaseRawQueryGuard;
use Pinoox\Component\Database\SqlAliasRewriter;
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

it('qualifies short sql aliases using the connection table prefix', function () {
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

    expect($manager->connectionTablePrefix('com_test_sql_alias'))->toBe('paper_')
        ->and($manager->sqlAlias('p', 'com_test_sql_alias'))->toBe('paper_p')
        ->and($manager->sqlCol('p', 'post_id', 'com_test_sql_alias'))->toBe('paper_p.post_id')
        ->and($manager->sqlAlias('paper_p', 'com_test_sql_alias'))->toBe('paper_p')
        ->and($manager->sqlAlias('u', 'platform'))->toBe('pinx_u')
        ->and($manager->sqlCol('u', 'user_id', 'platform'))->toBe('pinx_u.user_id');
});

it('matches compiled from aliases for prefixed app connections', function () {
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

    $sql = $manager->app('com_test_sql_alias')->table('post', 'p')->toSql();

    expect($sql)->toContain('"paper_p"')
        ->and($manager->sqlAlias('p', 'com_test_sql_alias'))->toBe('paper_p')
        ->and($manager->sqlCol('p', 'post_id', 'com_test_sql_alias'))->toBe('paper_p.post_id');
});

it('rewrites short aliases in selectRaw and groupByRaw for prefixed connections', function () {
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
        ->toContain('paper_p.post_id')
        ->toContain('paper_t.term_id')
        ->not->toContain(' p.post_id')
        ->not->toContain(' t.term_id');
});

it('does not double-prefix already qualified alias references', function () {
    $manager = new DatabaseManager(new Illuminate\Container\Container());
    $manager->registerCoreConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'paper_',
    ]);

    $query = $manager->getConnection()->table('post', 'p');

    expect(SqlAliasRewriter::qualifyRaw('paper_p.post_id', $query))->toBe('paper_p.post_id');
});

it('warns in debug mode when raw sql uses a short alias under a prefixed connection', function () {
    $notices = [];

    set_error_handler(function (int $errno, string $message) use (&$notices): bool {
        if ($errno === E_USER_NOTICE) {
            $notices[] = $message;
        }

        return true;
    });

    try {
        DatabaseRawQueryGuard::warnShortAliases(
            'select p.post_id from "paper_post" as "paper_p"',
            'paper_',
        );

        expect($notices)->toHaveCount(1)
            ->and($notices[0])->toContain('paper_p.post_id')
            ->and($notices[0])->toContain('Table::sqlCol');
    } finally {
        restore_error_handler();
    }
});

it('does not warn when raw sql already uses the qualified alias', function () {
    $notices = [];

    set_error_handler(function (int $errno, string $message) use (&$notices): bool {
        if ($errno === E_USER_NOTICE) {
            $notices[] = $message;
        }

        return true;
    });

    try {
        DatabaseRawQueryGuard::warnShortAliases(
            'select paper_p.post_id from "paper_post" as "paper_p"',
            'paper_',
        );

        expect($notices)->toBeEmpty();
    } finally {
        restore_error_handler();
    }
});
