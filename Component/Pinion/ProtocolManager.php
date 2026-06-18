<?php

namespace Pinoox\Component\Pinion;

use Pinoox\Pinion\Manager as PackageManager;
use Pinoox\Pinion\Result;

final class ProtocolManager extends PackageManager
{
    private StorageCompletion $storageCompletion;

    public function __construct(?\Pinoox\Pinion\Store $store = null, ?array $configOverrides = null, ?\Pinoox\Pinion\Contract\PathResolverInterface $paths = null)
    {
        parent::__construct($store, $configOverrides, $paths ?? new PinooxPathResolver());
        $this->storageCompletion = new StorageCompletion();
    }

    /**
     * @param list<string> $extensions
     * @param array<string, mixed> $meta
     */
    public function init(
        string $filename,
        int $size,
        string $destination,
        array $extensions = [],
        ?int $chunkSize = null,
        ?string $mime = null,
        ?string $fingerprint = null,
        ?string $fileHash = null,
        array $meta = [],
    ): Result {
        $meta = StorageContext::mergeDefaults($meta);

        if (StorageContext::usesStorage($meta)) {
            $meta['storage_destination'] = $destination;
            $destination = StorageContext::STAGING_REFERENCE;
        }

        return parent::init(
            $filename,
            $size,
            $destination,
            $extensions,
            $chunkSize,
            $mime,
            $fingerprint,
            $fileHash,
            $meta,
        );
    }

    public function complete(string $uploadId, ?string $fileHash = null): Result
    {
        $result = parent::complete($uploadId, $fileHash);

        if (!$result->success || $result->session === null || $result->path === null) {
            return $result;
        }

        if (!StorageContext::usesStorage($result->session->meta)) {
            return $result;
        }

        $published = $this->storageCompletion->publish($result->session, $result->path);

        if (!($published['success'] ?? false)) {
            return Result::fail((string) ($published['error'] ?? 'storage_publish_failed'), $result->session);
        }

        $meta = $result->session->meta;
        $meta['published'] = $published;

        $session = $this->cloneSessionWithMeta($result->session, $meta);

        return Result::ok(
            $session,
            (string) ($published['storage_key'] ?? $published['path'] ?? $result->path),
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function cloneSessionWithMeta(\Pinoox\Pinion\Session $session, array $meta): \Pinoox\Pinion\Session
    {
        return \Pinoox\Pinion\Session::fromArray(array_merge($session->toArray(), [
            'meta' => $meta,
            'final_path' => (string) ($meta['published']['storage_key'] ?? $meta['published']['path'] ?? $session->final_path),
        ]));
    }
}
