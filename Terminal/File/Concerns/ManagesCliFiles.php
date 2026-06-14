<?php

namespace Pinoox\Terminal\File\Concerns;

use Pinoox\Component\File\FileStorage;
use Pinoox\Component\Transport\TransportRuntime;
use Pinoox\Model\FileModel;
use Pinoox\Model\UserModel;
use Pinoox\Portal\Database\DB;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Pinoox\Terminal\User\Concerns\ManagesCliUsers;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

trait ManagesCliFiles
{
    use SelectsPackage;
    use ManagesCliUsers;

    protected function resolveFilePackage(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $sectionTitle = 'Manage files for',
    ): string {
        return $this->resolvePackageRequired($input, $output, $io, [
            'sectionTitle' => $sectionTitle,
        ]);
    }

    protected function resolveFilePackageInput(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $sectionTitle = 'Manage files for',
    ): string {
        $explicitPackage = (string) ($input->getArgument('package') ?? '');

        if ($explicitPackage !== '' && $this->looksLikePackageName($explicitPackage)) {
            return $explicitPackage;
        }

        return $this->resolveFilePackage($input, $output, $io, $sectionTitle);
    }

    protected function prepareFileScope(string $package): void
    {
        DB::ensureRegistered();
        FileModel::clearBootedModels();
        UserModel::clearBootedModels();

        TransportRuntime::use($package);
        FileModel::setPackage($package);
    }

    protected function resolveFileIdentifier(InputInterface $input): string
    {
        return trim((string) ($input->getArgument('file') ?? ''));
    }

    protected function resolveFile(string $identifier): ?FileModel
    {
        if ($identifier === '') {
            return null;
        }

        if (ctype_digit($identifier)) {
            return FileModel::query()->where('file_id', (int) $identifier)->first();
        }

        return FileModel::query()->where('hash_id', $identifier)->first();
    }

    protected function resolveFileInput(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $sectionTitle = 'Select file',
    ): ?FileModel {
        $identifier = $this->resolveFileIdentifier($input);

        if ($identifier !== '') {
            return $this->resolveFile($identifier);
        }

        if (!$input->isInteractive()) {
            throw new \RuntimeException('File is required in non-interactive mode.');
        }

        return $this->promptFileSelection($input, $output, $io, $sectionTitle);
    }

    protected function promptFileSelection(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $sectionTitle = 'Select file',
    ): ?FileModel {
        $files = FileModel::query()->orderByDesc('file_id')->limit(50)->get();

        if ($files->isEmpty()) {
            return null;
        }

        $io->section($sectionTitle);

        $table = new Table($output);
        $table->setHeaders(['ID', 'Name', 'Group', 'Ext', 'Size', 'Access', 'Storage']);
        foreach ($files as $file) {
            $table->addRow([
                $file->file_id,
                $file->file_realname ?: $file->file_name,
                $file->file_group ?: '—',
                $file->file_ext ?: '—',
                $this->formatFileSize($file->file_size),
                $file->file_access ?: '—',
                $this->storageStatusLabel($file),
            ]);
        }
        $table->render();

        $candidates = [];
        foreach ($files as $file) {
            $candidates[] = (string) $file->file_id;
            if (is_string($file->hash_id) && $file->hash_id !== '') {
                $candidates[] = $file->hash_id;
            }
        }

        $question = new Question('File id or hash_id: ');
        $question->setAutocompleterValues(array_values(array_unique($candidates)));
        $question->setValidator(function ($answer) {
            $answer = trim((string) $answer);
            if ($answer === '') {
                throw new \RuntimeException('File is required.');
            }

            $file = $this->resolveFile($answer);
            if ($file === null) {
                throw new \RuntimeException('File not found: ' . $answer);
            }

            return $file;
        });

        $selected = $this->getHelper('question')->ask($input, $output, $question);

        return $selected instanceof FileModel ? $selected : null;
    }

    protected function resolveDeleteMode(InputInterface $input): string
    {
        if ($input->getOption('db-only')) {
            return 'db-only';
        }

        if ($input->getOption('storage-only')) {
            return 'storage-only';
        }

        return 'both';
    }

    protected function deleteFile(FileModel $file, string $mode): bool
    {
        if ($mode === 'storage-only') {
            FileStorage::delete($file);

            return true;
        }

        if ($mode === 'db-only') {
            return FileModel::withoutEvents(function () use ($file) {
                return (bool) $file->delete();
            });
        }

        return (bool) $file->delete();
    }

    protected function fileExistsOnStorage(FileModel $file): bool
    {
        if (empty($file->file_name) || empty($file->file_path)) {
            return false;
        }

        try {
            $disk = FileStorage::disk($file->app, FileStorage::resolveDisk($file));
            $key = FileStorage::key((string) $file->file_path, (string) $file->file_name);

            if ($disk->exists($key)) {
                return true;
            }
        } catch (\Throwable) {
        }

        $legacy = path((string) $file->file_path, $file->app) . '/' . $file->file_name;

        return is_file($legacy);
    }

    protected function storageStatusLabel(FileModel $file): string
    {
        return $this->fileExistsOnStorage($file) ? 'present' : 'missing';
    }

    protected function formatFileSize(mixed $bytes): string
    {
        $bytes = (float) $bytes;

        if ($bytes <= 0) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), 2) . ' ' . $units[$power];
    }

    protected function deleteModeLabel(string $mode): string
    {
        return match ($mode) {
            'db-only' => 'database row only',
            'storage-only' => 'storage files only',
            default => 'database row and storage files',
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function fileRow(FileModel $file, bool $checkStorage = true): array
    {
        return [
            'file_id' => $file->file_id,
            'hash_id' => $file->hash_id,
            'user_id' => $file->user_id,
            'app' => $file->app,
            'file_group' => $file->file_group,
            'file_realname' => $file->file_realname,
            'file_name' => $file->file_name,
            'file_ext' => $file->file_ext,
            'file_path' => $file->file_path,
            'file_size' => $file->file_size,
            'file_size_label' => $this->formatFileSize($file->file_size),
            'file_access' => $file->file_access,
            'file_metadata' => $file->file_metadata,
            'file_link' => $file->file_link,
            'thumb_link' => $file->thumb_link,
            'storage' => $checkStorage ? $this->storageStatusLabel($file) : '—',
            'created_at' => $file->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $file->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    protected function mergeFileMetadata(?array $current, array $metadata): array
    {
        $current = is_array($current) ? $current : [];

        foreach ($metadata as $key => $value) {
            if ($value === null || $value === '') {
                unset($current[$key]);
                continue;
            }

            $current[$key] = $value;
        }

        return $current;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseFileMetadataJson(string $json): array
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Metadata must be valid JSON object.');
        }

        return $decoded;
    }
}
