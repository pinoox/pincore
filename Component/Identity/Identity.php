<?php

/**
 *      ****  *  *     *  ****  ****  *    *
 *      *  *  *  * *   *  *  *  *  *   *  *
 *      ****  *  *  *  *  *  *  *  *    *
 *      *     *  *   * *  *  *  *  *   *  *
 *      *     *  *    **  ****  ****  *    *
 * @author   Pinoox
 * @link https://www.pinoox.com/
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Component\Identity;

use Pinoox\Component\Store\Baker\FileHandler;
use Pinoox\Component\Store\Baker\FileHandlerInterface;
use Pinoox\Support\SystemConfig;
use Throwable;

/**
 * Stable per-install Pinoox ID. Created once, never rotated.
 */
class Identity
{
    public const PREFIX = 'px_';

    private const LOCK_TIMEOUT_MICROSECONDS = 3000000;

    private const LOCK_SLEEP_MICROSECONDS = 50000;

    private static ?self $default = null;

    private ?array $loaded = null;

    public function __construct(
        private readonly ?string $file = null,
        private readonly ?FileHandlerInterface $files = null,
    ) {
    }

    public static function default(): self
    {
        return self::$default ??= new self();
    }

    public static function flush(): void
    {
        self::$default = null;
    }

    /**
     * Create the install ID on first boot if it is missing.
     *
     * @return array{pinoox_id: string, created_at?: string}
     */
    public static function boot(): array
    {
        return self::default()->ensure();
    }

    /**
     * @return array{pinoox_id: string, created_at?: string}
     */
    public function ensure(): array
    {
        return $this->load();
    }

    public function id(): string
    {
        return (string) ($this->load()['pinoox_id'] ?? '');
    }

    public function createdAt(): ?string
    {
        $createdAt = $this->load()['created_at'] ?? null;

        return is_string($createdAt) && $createdAt !== '' ? $createdAt : null;
    }

    public function file(): string
    {
        return $this->file ?? SystemConfig::identityFile();
    }

    /**
     * @return array{pinoox_id: string, created_at?: string}
     */
    public function load(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $existing = $this->readValid();
        if ($existing !== null) {
            return $this->loaded = $existing;
        }

        return $this->loaded = $this->persistNew();
    }

    /**
     * @return array{pinoox_id: string, created_at?: string}|null
     */
    private function readValid(): ?array
    {
        $file = $this->file();
        if (!is_file($file)) {
            return null;
        }

        try {
            $data = $this->handler()->retrieve($file);
        } catch (Throwable) {
            return null;
        }

        return $this->normalize($data);
    }

    /**
     * @return array{pinoox_id: string, created_at?: string}
     */
    private function persistNew(): array
    {
        $generated = $this->generate();
        $lockFile = $this->file() . '.ensure.lock';
        $lock = $this->lock($lockFile);

        try {
            $existing = $this->readValid();
            if ($existing !== null) {
                return $existing;
            }

            try {
                $this->handler()->store($this->file(), $this->export($generated));
            } catch (Throwable) {
                return $generated;
            }

            return $this->readValid() ?? $generated;
        } finally {
            $this->unlock($lock, $lockFile);
        }
    }

    /**
     * @return array{pinoox_id: string, created_at: string}
     */
    private function generate(): array
    {
        return [
            'pinoox_id' => $this->generateId(),
            'created_at' => gmdate('c'),
        ];
    }

    private function generateId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return self::PREFIX . bin2hex($bytes);
    }

    /**
     * @return array{pinoox_id: string, created_at?: string}|null
     */
    private function normalize(mixed $data): ?array
    {
        if (!is_array($data)) {
            return null;
        }

        $id = $data['pinoox_id'] ?? null;
        if (!is_string($id)) {
            return null;
        }

        $id = trim($id);
        if ($id === '') {
            return null;
        }

        $normalized = ['pinoox_id' => $id];
        $createdAt = $data['created_at'] ?? null;
        if (is_string($createdAt) && $createdAt !== '') {
            $normalized['created_at'] = $createdAt;
        }

        return $normalized;
    }

    /**
     * @param array{pinoox_id: string, created_at?: string} $data
     */
    private function export(array $data): string
    {
        return '<?php' . "\n\n" . 'return ' . var_export($data, true) . ";\n";
    }

    private function handler(): FileHandlerInterface
    {
        return $this->files ?? new FileHandler();
    }

    /**
     * @return resource|null
     */
    private function lock(string $lockFile)
    {
        $directory = dirname($lockFile);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            return null;
        }

        $handle = @fopen($lockFile, 'c');
        if ($handle === false) {
            return null;
        }

        $start = microtime(true);
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }

            usleep(self::LOCK_SLEEP_MICROSECONDS);
        } while (((microtime(true) - $start) * 1000000) < self::LOCK_TIMEOUT_MICROSECONDS);

        fclose($handle);

        return null;
    }

    /**
     * @param resource|null $handle
     */
    private function unlock(mixed $handle, string $lockFile): void
    {
        if (!is_resource($handle)) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
        @unlink($lockFile);
    }
}
