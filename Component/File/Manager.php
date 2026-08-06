<?php

namespace Pinoox\Component\File;

use Illuminate\Filesystem\FilesystemAdapter;
use Pinoox\Component\Upload\FileUploaderFactory;
use Pinoox\Model\FileModel;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class Manager
{
    public function upload(UploadedFile|string $source): UploadBuilder
    {
        return new UploadBuilder(UploadBuilder::resolveSource($source));
    }

    public function find(int|string $fileId): ?FileModel
    {
        if (is_string($fileId) && !ctype_digit($fileId)) {
            return FileModel::where('hash_id', $fileId)->first();
        }

        return FileModel::where('file_id', (int) $fileId)->first();
    }

    public function remove(int|string $fileId): bool
    {
        $record = is_int($fileId) || ctype_digit((string) $fileId)
            ? null
            : $this->find($fileId);

        $id = $record?->file_id ?? (int) $fileId;

        return (bool) (new FileUploaderFactory())->delete($id);
    }

    public function url(int|string|FileModel|null $file): ?string
    {
        $record = $this->resolve($file);

        return $record?->file_link;
    }

    public function thumb(int|string|FileModel|null $file): ?string
    {
        $record = $this->resolve($file);

        return $record?->thumb_link;
    }

    /**
     * Laravel-style temporary URL for a stored file (S3 signed or local signed /file/{hash}).
     */
    public function temporaryUrl(
        int|string|FileModel|null $file,
        \DateTimeInterface|\DateInterval|int $expiration,
        bool $thumb = false,
    ): ?string {
        $record = $this->resolve($file);
        if (!$record) {
            return null;
        }

        return FileTemporaryUrl::make($record, $expiration, $thumb);
    }

    /**
     * @return array<string, mixed>
     */
    public function info(int|string|FileModel|null $file): array
    {
        $record = $this->resolve($file);
        if (!$record) {
            return [];
        }

        return [
            'file_id' => $record->file_id,
            'hash_id' => $record->hash_id,
            'file_group' => $record->file_group,
            'file_realname' => $record->file_realname,
            'file_name' => $record->file_name,
            'file_ext' => $record->file_ext,
            'file_path' => $record->file_path,
            'file_size' => $record->file_size,
            'file_access' => $record->file_access,
            'file_disk' => $record->file_disk,
            'file_metadata' => $record->file_metadata ?? [],
            'url' => $record->file_link,
            'thumb' => $record->thumb_link,
            'created_at' => $record->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return list<FileModel>
     */
    public function listByGroup(string $group): array
    {
        return FileModel::where('file_group', $group)->get()->all();
    }

    public function attach(int $fileId, object $model, string $column): bool
    {
        if (!method_exists($model, 'update')) {
            return false;
        }

        $key = method_exists($model, 'getKeyName') ? $model->getKeyName() : 'id';

        return (bool) $model->where($key, $model->{$key})->update([$column => $fileId]);
    }

    public function setPackage(string $package): void
    {
        FileModel::setPackage($package);
    }

    public function storage(?string $disk = null): FilesystemAdapter
    {
        return FileStorage::disk(null, $disk);
    }

    private function resolve(int|string|FileModel|null $file): ?FileModel
    {
        if ($file instanceof FileModel) {
            return $file;
        }

        if ($file === null) {
            return null;
        }

        return $this->find($file);
    }
}
