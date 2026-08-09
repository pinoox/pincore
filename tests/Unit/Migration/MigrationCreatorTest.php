<?php

use Pinoox\Component\Migration\MigrationCreator;
use Pinoox\Component\Migration\MigrationNameParser;

function migrationCreatorTempDir(): string
{
    $dir = sys_get_temp_dir() . '/pinoox_migration_creator_' . uniqid('', true);
    mkdir($dir, 0755, true);

    return $dir;
}

afterEach(function () {
    if (!isset($this->migrationTempDir) || !is_dir($this->migrationTempDir)) {
        return;
    }

    foreach (glob($this->migrationTempDir . '/*.php') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->migrationTempDir);
});

it('writes create, update, and drop stubs from the migration name', function (string $name, string $type, string $needle) {
    $this->migrationTempDir = migrationCreatorTempDir();

    $result = (new MigrationCreator())->create(
        $this->migrationTempDir,
        $name,
        'App\\com_acme_blog\\database\\migrations',
    );

    expect($result['type'])->toBe($type)
        ->and($result['path'])->toBeFile()
        ->and($result['name'])->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_.+$/')
        ->and(file_get_contents($result['path']))->toContain($needle)
        ->and(file_get_contents($result['path']))->toContain("namespace App\\com_acme_blog\\database\\migrations;");
})->with([
    ['posts', MigrationNameParser::TYPE_CREATE, "\$this->schema->create('posts'"],
    ['add_email_to_users', MigrationNameParser::TYPE_UPDATE, "\$this->schema->table('users'"],
    ['drop_posts_table', MigrationNameParser::TYPE_DROP, "\$this->schema->dropIfExists('posts'"],
]);

it('uses --table for a blank-looking name without wrapping create_', function () {
    $this->migrationTempDir = migrationCreatorTempDir();

    $result = (new MigrationCreator())->create(
        $this->migrationTempDir,
        'sync_legacy_flags',
        'App\\com_acme_blog\\database\\migrations',
        null,
        'users',
    );

    expect($result['type'])->toBe(MigrationNameParser::TYPE_UPDATE)
        ->and($result['table'])->toBe('users')
        ->and($result['name'])->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_sync_legacy_flags$/')
        ->and(file_get_contents($result['path']))->toContain("\$this->schema->table('users'");
});
