<?php

use Pinoox\Model\FileModel;
use Pinoox\Terminal\File\Concerns\ManagesCliFiles;
use Pinoox\Terminal\File\FileDeleteCommand;
use Pinoox\Terminal\File\FileListCommand;
use Pinoox\Terminal\File\FilePurgeCommand;
use Pinoox\Terminal\File\FileShowCommand;
use Pinoox\Terminal\File\FileUpdateCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;

it('registers file CLI commands', function () {
    $application = cliApplication([
        new FileListCommand(),
        new FileShowCommand(),
        new FileDeleteCommand(),
        new FileUpdateCommand(),
        new FilePurgeCommand(),
    ]);

    expect($application->has('file:list'))->toBeTrue()
        ->and($application->has('file:show'))->toBeTrue()
        ->and($application->has('file:delete'))->toBeTrue()
        ->and($application->has('file:update'))->toBeTrue()
        ->and($application->has('file:purge'))->toBeTrue();
});

it('requires optional package argument on file commands', function () {
    foreach ([
        new FileListCommand(),
        new FileShowCommand(),
        new FileDeleteCommand(),
        new FileUpdateCommand(),
    ] as $command) {
        $argument = $command->getDefinition()->getArgument('package');

        expect($argument->isRequired())->toBeFalse()
            ->and($argument->getDefault())->toBeNull();
    }
});

it('resolves file delete modes from CLI options', function () {
    $probe = cliTraitProbe([ManagesCliFiles::class]);
    $definition = $probe->getDefinition();
    $definition->addOption(new Symfony\Component\Console\Input\InputOption('db-only'));
    $definition->addOption(new Symfony\Component\Console\Input\InputOption('storage-only'));

    expect(cliTraitInvoke($probe, 'resolveDeleteMode', new ArrayInput([], $definition)))->toBe('both')
        ->and(cliTraitInvoke($probe, 'resolveDeleteMode', new ArrayInput(['--db-only' => true], $definition)))->toBe('db-only')
        ->and(cliTraitInvoke($probe, 'resolveDeleteMode', new ArrayInput(['--storage-only' => true], $definition)))->toBe('storage-only');
});

it('formats human-readable file sizes', function () {
    $probe = cliTraitProbe([ManagesCliFiles::class]);

    expect(cliTraitInvoke($probe, 'formatFileSize', 0))->toBe('—')
        ->and(cliTraitInvoke($probe, 'formatFileSize', 512))->toBe('512 B')
        ->and(cliTraitInvoke($probe, 'formatFileSize', 2048))->toBe('2 KB');
});

it('labels delete modes for CLI output', function () {
    $probe = cliTraitProbe([ManagesCliFiles::class]);

    expect(cliTraitInvoke($probe, 'deleteModeLabel', 'both'))
        ->toBe('database row and storage files')
        ->and(cliTraitInvoke($probe, 'deleteModeLabel', 'db-only'))
        ->toBe('database row only')
        ->and(cliTraitInvoke($probe, 'deleteModeLabel', 'storage-only'))
        ->toBe('storage files only');
});

it('parses and merges file metadata json', function () {
    $probe = cliTraitProbe([ManagesCliFiles::class]);

    expect(cliTraitInvoke($probe, 'parseFileMetadataJson', '{"alt":"logo"}'))
        ->toBe(['alt' => 'logo']);

    $merged = cliTraitInvoke($probe, 'mergeFileMetadata', ['alt' => 'old'], [
        'alt' => 'new',
        'caption' => 'hero',
    ]);

    expect($merged)->toBe(['alt' => 'new', 'caption' => 'hero']);

    $cleared = cliTraitInvoke($probe, 'mergeFileMetadata', ['alt' => 'old', 'caption' => 'hero'], [
        'caption' => '',
    ]);

    expect($cleared)->toBe(['alt' => 'old']);
});

it('rejects invalid file metadata json', function () {
    $probe = cliTraitProbe([ManagesCliFiles::class]);

    expect(fn () => cliTraitInvoke($probe, 'parseFileMetadataJson', 'not-json'))
        ->toThrow(InvalidArgumentException::class);
});

it('builds file row payload without storage probe', function () {
    $probe = cliTraitProbe([ManagesCliFiles::class]);

    $file = new FileModel();
    $file->setAppends([]);
    $file->file_id = 12;
    $file->hash_id = 'abc123';
    $file->file_realname = 'photo.jpg';
    $file->file_name = 'photo_hash.jpg';
    $file->file_path = 'uploads/photos';
    $file->file_ext = 'jpg';
    $file->file_size = 1024;
    $file->file_access = 'public';
    $file->app = 'com_test_cli_file';

    $row = cliTraitInvoke($probe, 'fileRow', $file, false);

    expect($row['file_id'])->toBe(12)
        ->and($row['hash_id'])->toBe('abc123')
        ->and($row['file_size_label'])->toBe('1 KB')
        ->and($row['storage'])->toBe('—');
});

it('exposes delete mode options on file:delete command', function () {
    $command = new FileDeleteCommand();
    $definition = $command->getDefinition();

    expect($definition->hasOption('db-only'))->toBeTrue()
        ->and($definition->hasOption('storage-only'))->toBeTrue()
        ->and($definition->getArgument('file'))->toBeInstanceOf(InputArgument::class);
});
