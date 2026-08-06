<?php

namespace Pinoox\Component\Http\File;

use Pinoox\Component\File\UploadBuilder;
use Pinoox\Portal\File;
use Symfony\Component\HttpFoundation\File\UploadedFile as UploadedFileSymfony;

class UploadedFile extends UploadedFileSymfony
{
    /**
     * Laravel-style store helper.
     *
     * Second argument is a disk name (`public`, `local`, `s3`, …).
     * Shortcuts: `private` → private disk via UploadBuilder::private().
     * Omit disk to use the app `filesystem.disk` default.
     */
    public function store(string $destination, ?string $disk = null): UploadBuilder
    {
        $builder = File::upload($this)->to($destination);

        if ($disk === null || $disk === '') {
            return $builder;
        }

        $disk = strtolower(trim($disk));

        return match ($disk) {
            'public' => $builder->public(),
            'private' => $builder->private(),
            default => $builder->disk($disk),
        };
    }

    public static function createFromBase($file, $test = false)
    {
        if (is_array($file)) {
            return new static($file['tmp_name'], $file['name'], $file['type'], $file['error'], false);
        }

        return $file instanceof static ? $file : new static(
            $file->getPathname(),
            $file->getClientOriginalName(),
            $file->getClientMimeType(),
            $file->getError(),
            $test
        );
    }
}
