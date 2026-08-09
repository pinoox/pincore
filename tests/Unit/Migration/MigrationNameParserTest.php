<?php

use Pinoox\Component\Migration\MigrationNameParser;

it('wraps a short table name as create_*_table', function () {
    expect(MigrationNameParser::parse('posts'))->toMatchArray([
        'type' => MigrationNameParser::TYPE_CREATE,
        'table' => 'posts',
        'name' => 'create_posts_table',
    ]);
});

it('normalizes CreatePosts and create_posts to create_posts_table', function (string $input) {
    expect(MigrationNameParser::parse($input))->toMatchArray([
        'type' => MigrationNameParser::TYPE_CREATE,
        'table' => 'posts',
        'name' => 'create_posts_table',
    ]);
})->with(['CreatePosts', 'create_posts', 'create_posts_table']);

it('does not double-prefix create or add names', function () {
    expect(MigrationNameParser::parse('create_products_table')['name'])->toBe('create_products_table')
        ->and(MigrationNameParser::parse('add_email_to_users')['name'])->toBe('add_email_to_users')
        ->and(MigrationNameParser::parse('drop_posts_table')['name'])->toBe('drop_posts_table');
});

it('guesses update stubs from laravel-style add/drop/modify names', function (string $input, string $table) {
    expect(MigrationNameParser::parse($input))->toMatchArray([
        'type' => MigrationNameParser::TYPE_UPDATE,
        'table' => $table,
        'name' => MigrationNameParser::toSnakeCase($input),
    ]);
})->with([
    ['add_email_to_users', 'users'],
    ['add_email_to_users_table', 'users'],
    ['drop_email_from_users', 'users'],
    ['remove_legacy_from_orders', 'orders'],
    ['modify_status_in_orders', 'orders'],
    ['update_price_in_products', 'products'],
    ['rename_title_in_posts', 'posts'],
    ['alter_users_table', 'users'],
    ['change_status_in_orders', 'orders'],
]);

it('guesses drop stubs from drop_*_table names', function () {
    expect(MigrationNameParser::parse('drop_posts_table'))->toMatchArray([
        'type' => MigrationNameParser::TYPE_DROP,
        'table' => 'posts',
        'name' => 'drop_posts_table',
    ]);
});

it('honors --create and --table over name guessing', function () {
    expect(MigrationNameParser::parse('sync_legacy_flags', table: 'users'))->toMatchArray([
        'type' => MigrationNameParser::TYPE_UPDATE,
        'table' => 'users',
        'name' => 'sync_legacy_flags',
    ])->and(MigrationNameParser::parse('add_status', create: 'orders'))->toMatchArray([
        'type' => MigrationNameParser::TYPE_CREATE,
        'table' => 'orders',
        'name' => 'add_status',
    ])->and(MigrationNameParser::parse('foo', create: 'posts', table: 'users'))->toMatchArray([
        'type' => MigrationNameParser::TYPE_CREATE,
        'table' => 'posts',
        'name' => 'foo',
    ]);
});

it('keeps unmatched verb names as blank stubs', function () {
    expect(MigrationNameParser::parse('drop_legacy'))->toMatchArray([
        'type' => MigrationNameParser::TYPE_BLANK,
        'table' => null,
        'name' => 'drop_legacy',
    ]);
});

it('extracts table names from timestamped filenames', function () {
    expect(MigrationNameParser::extractTableName('2026_08_08_120000_create_posts_table'))->toBe('posts')
        ->and(MigrationNameParser::extractTableName('2026_08_08_120000_add_email_to_users'))->toBe('users')
        ->and(MigrationNameParser::extractTableName('2026_08_08_120000_drop_posts_table'))->toBe('posts')
        ->and(MigrationNameParser::extractTableName('2026_08_08_120000_drop_email_from_users_table'))->toBe('users')
        ->and(MigrationNameParser::extractTableName('2026_08_08_120000_unique_label_name_per_project'))->toBeNull()
        ->and(MigrationNameParser::extractTableName('2026_06_07_120000_create_access_tables'))->toBeNull();
});
