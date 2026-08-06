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
            ->package(isset($meta['package']) ? (string) $meta['package'] : null);

        $this->applyDiskAndAccess($builder, $meta);

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
     * Prefer disk / public() / private(); access() only for edge overrides.
     *
     * @param array<string, mixed> $meta
     */
    private function applyDiskAndAccess(UploadBuilder $builder, array $meta): void
    {
        $disk = isset($meta['disk']) ? trim((string) $meta['disk']) : '';
        $access = strtolower(trim((string) ($meta['access'] ?? '')));

        if ($disk !== '') {
            $builder->disk($disk);
        } elseif ($access === 'public') {
            $builder->public();
        } elseif ($access === 'private') {
            $builder->private();
        }

        // Edge case: shared link on a private disk (or the reverse).
        if ($access !== '' && $disk !== '') {
            $diskImpliesPublic = $disk === 'public';
            $accessIsPublic = $access === 'public';
            if ($diskImpliesPublic !== $accessIsPublic) {
                $builder->access($access);
            }
        }
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
