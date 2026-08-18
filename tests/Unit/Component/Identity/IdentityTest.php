<?php

use Pinoox\Component\Identity\Identity;

function identityTestFile(): string
{
    return sys_get_temp_dir() . '/pinoox-identity-' . bin2hex(random_bytes(8)) . '.php';
}

function cleanupIdentityTestFile(string $file): void
{
    @unlink($file);
    @unlink($file . '.lock');
    @unlink($file . '.ensure.lock');
}

it('creates a pinoox id on first load and keeps it', function () {
    $file = identityTestFile();

    try {
        $identity = new Identity($file);
        $id = $identity->id();

        expect($id)->toMatch('/^px_[a-f0-9]{32}$/')
            ->and((new Identity($file))->id())->toBe($id)
            ->and($identity->createdAt())->not->toBeEmpty();
    } finally {
        cleanupIdentityTestFile($file);
    }
});

it('does not replace an existing pinoox id', function () {
    $file = identityTestFile();

    try {
        $existing = 'px_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        file_put_contents($file, "<?php\n\nreturn ['pinoox_id' => '{$existing}'];\n");

        expect((new Identity($file))->id())->toBe($existing);
    } finally {
        cleanupIdentityTestFile($file);
    }
});

it('keeps a custom non-empty id already stored on disk', function () {
    $file = identityTestFile();

    try {
        file_put_contents($file, "<?php\n\nreturn ['pinoox_id' => 'custom-install-1'];\n");

        expect((new Identity($file))->id())->toBe('custom-install-1');
    } finally {
        cleanupIdentityTestFile($file);
    }
});
