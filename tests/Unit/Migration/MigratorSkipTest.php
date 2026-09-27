<?php

use Pinoox\Component\Migration\Migrator;

it('does not skip unrecorded create migrations prematurely when table exists', function () {
    $migrator = new Migrator('platform');

    $method = new ReflectionMethod($migrator, 'shouldSkipMigrationExecution');
    $method->setAccessible(true);

    $skip = $method->invoke($migrator, [
        'fileName' => '2026_09_18_000019_create_collection_items_table',
        'packageName' => 'platform',
    ]);

    expect($skip)->toBeFalse();
});
