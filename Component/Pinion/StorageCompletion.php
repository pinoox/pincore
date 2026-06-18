<?php

namespace Pinoox\Component\Pinion;

use Pinoox\Component\File\UploadBuilder;
use Pinoox\Component\File\UploadResult;
use Pinoox\Pinion\Session;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class StorageCompletion
{
    /**
     * @return array{success: bool, error?: string, file_id?: int, url?: string|null, thumb?: string|null, path?: string, storage_key?: string, disk?: string, package?: string}
     */
    public function publish(Session $session, string $assembledPath): array
    {
        if (!is_file($assembledPath)) {
            return ['success' => false, 'error' => 'assembled_file_missing'];
        }

        $meta = StorageContext::mergeDefaults($session->meta);
        $destination = StorageContext::storageDestination($meta);

        $uploadedFile = new UploadedFile(
            $assembledPath,
            $session->filename,
            $session->mime ?: 'application/octet-stream',
            null,
            true,
        );

        $builder = (new UploadBuilder($uploadedFile))
            ->to($destination)
            ->access((string) ($meta['access'] ?? 'public'))
            ->disk(isset($meta['disk']) ? (string) $meta['disk'] : null)
            ->package(isset($meta['package']) ? (string) $meta['package'] : null);

        if (!empty($session->extensions)) {
            $builder->extensions($session->extensions);
        }

        if (!($meta['record'] ?? true)) {
            $builder->diskOnly();
        }

        if (!empty($meta['group'])) {
            $builder->group((string) $meta['group']);
        }

        if (!empty($meta['metadata']) && is_array($meta['metadata'])) {
            $builder->metadata($meta['metadata']);
        }

        $result = $builder->save();

        @unlink($assembledPath);

        return $this->formatResult($result, $meta);
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{success: bool, error?: string, file_id?: int, url?: string|null, thumb?: string|null, path?: string, storage_key?: string, disk?: string, package?: string}
     */
    private function formatResult(UploadResult $result, array $meta): array
    {
        if (!$result->success) {
            return [
                'success' => false,
                'error' => is_string($result->error) ? $result->error : 'storage_publish_failed',
            ];
        }

        $payload = [
            'success' => true,
            'path' => $result->path,
            'storage_key' => $result->path,
            'disk' => (string) ($meta['disk'] ?? 'local'),
            'package' => (string) ($meta['package'] ?? ''),
        ];

        if ($result->id !== null) {
            $payload['file_id'] = $result->id;
            $payload['url'] = $result->url;
            $payload['thumb'] = $result->thumb;
        }

        return $payload;
    }
}
