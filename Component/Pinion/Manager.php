<?php

namespace Pinoox\Component\Pinion;

use Pinoox\Pinion\HttpHandler as PackageHttpHandler;
use Pinoox\Pinion\Result;

final class Manager
{
    private ProtocolManager $inner;

    public function __construct()
    {
        $this->inner = new ProtocolManager(
            configOverrides: PinionConfig::resolve(),
            paths: new PinooxPathResolver(),
        );
    }

    public function begin(): \Pinoox\Pinion\Builder
    {
        return $this->inner->begin();
    }

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
        return $this->inner->init($filename, $size, $destination, $extensions, $chunkSize, $mime, $fingerprint, $fileHash, $meta);
    }

    public function receive(string $uploadId, int $index, string $binary, ?string $chunkHash = null): Result
    {
        return $this->inner->receive($uploadId, $index, $binary, $chunkHash);
    }

    public function complete(string $uploadId, ?string $fileHash = null): Result
    {
        return $this->inner->complete($uploadId, $fileHash);
    }

    public function abort(string $uploadId): bool
    {
        return $this->inner->abort($uploadId);
    }

    public function status(string $uploadId): ?\Pinoox\Pinion\Session
    {
        return $this->inner->status($uploadId);
    }

    public function list(?string $status = null): array
    {
        return $this->inner->list($status);
    }

    public function cleanExpired(): int
    {
        return $this->inner->cleanExpired();
    }

    public function http(array $defaults = []): HttpHandler
    {
        return HttpHandler::make($this->inner, $defaults);
    }

    public function package(): ProtocolManager
    {
        return $this->inner;
    }
}
