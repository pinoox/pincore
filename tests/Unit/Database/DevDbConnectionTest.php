<?php

use Pinoox\Component\Database\Connections\DevDbConnection;

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

