<?php

namespace Pinoox\Component\Server\Share;

final class ShareStateStore
{
    private string $path;

    public function __construct(string $projectRoot)
    {
        $this->path = ShareToolkit::binDir($projectRoot) . DIRECTORY_SEPARATOR . 'share-state.json';
    }

    public function lastProviderId(): ?string
    {
        $data = $this->read();

        $id = strtolower(trim((string) ($data['last_provider'] ?? '')));

        return $id !== '' ? $id : null;
    }

    public function remember(string $providerId): void
    {
        $data = $this->read();
        $data['last_provider'] = strtolower(trim($providerId));
        $data['last_success_at'] = time();

        file_put_contents($this->path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
