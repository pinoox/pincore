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
