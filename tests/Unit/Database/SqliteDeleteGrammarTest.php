<?php

use Pinoox\Component\Database\Connections\SQLiteConnection;

it('compiles sqlite delete as DELETE FROM table without mysql alias form', function () {
    $pdo = new PDO('sqlite::memory:');
    $connection = new SQLiteConnection($pdo, '', 'orbit_');

    $connection->getSchemaBuilder()->create('labels', function ($table) {
        $table->increments('id');
        $table->unsignedInteger('project_id')->nullable();
    });
    $connection->getSchemaBuilder()->create('projects', function ($table) {
        $table->increments('id');
    });

    $connection->table('labels')->insert([
        ['project_id' => 99],
        ['project_id' => null],
    ]);

    $query = $connection->table('labels')
        ->whereNotNull('project_id')
        ->whereNotIn('project_id', function ($q) {
            $q->select('id')->from('projects');
        });

    $sql = $query->getGrammar()->compileDelete($query);

    expect($sql)->toStartWith('delete from ')
        ->and($sql)->not->toMatch('/^delete\s+"[^"]+"\s+from/i');

    $deleted = $query->delete();

    expect($deleted)->toBe(1)
        ->and($connection->table('labels')->count())->toBe(1);
});

it('keeps logical table aliases on sqlite delete so qualified where columns resolve', function () {
    $pdo = new PDO('sqlite::memory:');
    $connection = new SQLiteConnection($pdo, '', 'pinx_');

    $connection->getSchemaBuilder()->create('history', function ($table) {
        $table->increments('id');
        $table->string('type');
        $table->string('migration');
        $table->string('app');
    });

    $connection->table('history')->insert([
        [
            'type' => 'migration',
            'migration' => '2026_08_08_000001_create_custom_fields_table',
            'app' => 'com_pinoox_orbit',
        ],
        [
            'type' => 'migration',
            'migration' => 'keep_me',
            'app' => 'com_other',
        ],
    ]);

    $query = $connection->table('history')
        ->where('history.type', 'migration')
        ->where('history.migration', '2026_08_08_000001_create_custom_fields_table')
        ->where('history.app', 'com_pinoox_orbit');

    $sql = $query->getGrammar()->compileDelete($query);

    expect($sql)->toStartWith('delete from ')
        ->and($sql)->toContain(' as ')
        ->and($sql)->toContain('"history"')
        ->and($sql)->not->toMatch('/^delete\s+"[^"]+"\s+from/i');

    $deleted = $query->delete();

    expect($deleted)->toBe(1)
        ->and($connection->table('history')->count())->toBe(1)
        ->and($connection->table('history')->value('migration'))->toBe('keep_me');
});
