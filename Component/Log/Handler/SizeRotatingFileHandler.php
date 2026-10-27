<?php

namespace Pinoox\Component\Log\Handler;

use DateTimeZone;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Utils;

/**
 * SizeRotatingFileHandler
 *
 * Rotates log files when they reach a maximum file size (e.g. 10MB)
 * and/or on a daily basis, preventing huge multi-hundred megabyte log files.
 * Older rotated files can optionally be gzipped (.gz) and automatically pruned.
 */
class SizeRotatingFileHandler extends RotatingFileHandler
{
    protected int $maxFileSize;
    protected bool $rotateByDate;
    protected bool $compress;

    /**
     * @param string $filename Base log file path
     * @param int $maxFiles Maximum number of rotated files to retain
     * @param int|string|Level $level Minimum logging level
     * @param int $maxFileSize Max size in bytes before rotating (default 10MB, 0 for unlimited)
     * @param bool $rotateByDate Whether to also rotate files by date (daily)
     * @param bool $compress Whether to gzip compressed rotated backups
     * @param bool $bubble Whether the messages that are handled can bubble up
     * @param int|null $filePermission Optional file permissions
     * @param bool $useLocking Try to lock log file before doing any writes
     * @param string $dateFormat Date format for daily files
     * @param string $filenameFormat Filename format for daily files
     * @param DateTimeZone|null $timezone Optional timezone
     */
    public function __construct(
        string $filename,
        int $maxFiles = 14,
        int|string|Level $level = Level::Debug,
        int $maxFileSize = 10485760,
        bool $rotateByDate = true,
        bool $compress = false,
        bool $bubble = true,
        ?int $filePermission = null,
        bool $useLocking = true,
        string $dateFormat = self::FILE_PER_DAY,
        string $filenameFormat = '{filename}-{date}',
        ?DateTimeZone $timezone = null,
    ) {
        $this->maxFileSize = max(0, $maxFileSize);
        $this->rotateByDate = $rotateByDate;
        $this->compress = $compress;

        if (!$rotateByDate) {
            $this->filename = Utils::canonicalizePath($filename);
            $this->maxFiles = $maxFiles;
            $this->url = $this->filename;
            $this->timezone = $timezone;
            $this->nextRotation = new \DateTimeImmutable('+100 years');

            // Initialize StreamHandler directly without date formatting
            \Monolog\Handler\StreamHandler::__construct($this->url, $level, $bubble, $filePermission, $useLocking);
        } else {
            parent::__construct(
                $filename,
                $maxFiles,
                $level,
                $bubble,
                $filePermission,
                $useLocking,
                $dateFormat,
                $filenameFormat,
                $timezone,
            );
        }
    }

    /**
     * @inheritDoc
     */
    protected function write(LogRecord $record): void
    {
        // 1. Date rotation check (when rotateByDate is true)
        if ($this->rotateByDate) {
            if (null === $this->mustRotate) {
                $this->mustRotate = null === $this->url || !file_exists($this->url);
            }

            if ($this->nextRotation <= $record->datetime) {
                $this->mustRotate = true;
                $this->close();
            }
        }

        // 2. Size rotation check (when maxFileSize > 0)
        if ($this->shouldRotateSize()) {
            $this->rotateSize();
        }

        // 3. Write log entry
        \Monolog\Handler\StreamHandler::write($record);

        // 4. Complete date rotation if triggered
        if ($this->rotateByDate && true === $this->mustRotate) {
            $this->close();
        }
    }

    /**
     * Check if active log file exceeds maximum size threshold.
     */
    protected function shouldRotateSize(): bool
    {
        if ($this->maxFileSize <= 0) {
            return false;
        }

        $file = $this->url;
        if (!is_string($file) || !file_exists($file)) {
            return false;
        }

        clearstatcache(true, $file);
        $size = @filesize($file);

        return $size !== false && $size >= $this->maxFileSize;
    }

    /**
     * Perform size rotation by shifting existing parts and archiving the active log.
     */
    protected function rotateSize(): void
    {
        $this->close();

        $file = $this->url;
        if (!is_string($file) || !file_exists($file)) {
            return;
        }

        clearstatcache(true, $file);
        $size = @filesize($file);
        if ($size === false || $size < $this->maxFileSize) {
            return;
        }

        $pathInfo = pathinfo($file);
        $dirname = $pathInfo['dirname'] ?? '.';
        $filename = $pathInfo['filename'];
        $extension = !empty($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';

        $limit = $this->maxFiles > 0 ? $this->maxFiles : 14;

        // Shift existing parts: .2 -> .3, .1 -> .2, etc.
        for ($i = $limit; $i >= 1; $i--) {
            $currentPart = $dirname . '/' . $filename . '.' . $i . $extension;
            $currentPartGz = $currentPart . '.gz';

            if ($i === $limit) {
                if (file_exists($currentPart)) {
                    @unlink($currentPart);
                }
                if (file_exists($currentPartGz)) {
                    @unlink($currentPartGz);
                }
                continue;
            }

            $nextPart = $dirname . '/' . $filename . '.' . ($i + 1) . $extension;
            $nextPartGz = $nextPart . '.gz';

            if (file_exists($currentPartGz)) {
                @rename($currentPartGz, $nextPartGz);
            } elseif (file_exists($currentPart)) {
                @rename($currentPart, $nextPart);
            }
        }

        // Archive active log as part 1
        $targetPart = $dirname . '/' . $filename . '.1' . $extension;
        $targetGz = $targetPart . '.gz';

        if ($this->compress && function_exists('deflate_init')) {
            if ($this->compressFile($file, $targetGz)) {
                @unlink($file);
            } else {
                @rename($file, $targetPart);
            }
        } else {
            @rename($file, $targetPart);
        }

        $this->cleanupOldFiles();
    }

    /**
     * Clean up older log files when total file count exceeds maxFiles.
     */
    protected function cleanupOldFiles(): void
    {
        if ($this->maxFiles <= 0) {
            return;
        }

        $fileInfo = pathinfo($this->filename);
        $dirname = $fileInfo['dirname'] ?? '.';
        $prefix = $fileInfo['filename'];

        $pattern = $dirname . '/' . $prefix . '*';
        $files = glob($pattern);

        if ($files === false || empty($files)) {
            return;
        }

        $files = array_filter($files, 'is_file');
        $currentUrl = $this->url;

        usort($files, static function (string $a, string $b): int {
            return filemtime($b) <=> filemtime($a);
        });

        if (count($files) > $this->maxFiles) {
            $toDelete = array_slice($files, $this->maxFiles);
            foreach ($toDelete as $f) {
                if ($f !== $currentUrl) {
                    @unlink($f);
                }
            }
        }
    }

    /**
     * Compress a source log file to gzip format in chunks to preserve memory.
     */
    protected function compressFile(string $source, string $targetGz): bool
    {
        $sourceHandle = @fopen($source, 'rb');
        if ($sourceHandle === false) {
            return false;
        }

        $gzHandle = @fopen($targetGz, 'wb');
        if ($gzHandle === false) {
            @fclose($sourceHandle);
            return false;
        }

        $deflate = @deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]);
        if ($deflate === false) {
            @fclose($sourceHandle);
            @fclose($gzHandle);
            return false;
        }

        while (!feof($sourceHandle)) {
            $chunk = fread($sourceHandle, 65536);
            if ($chunk === false) {
                break;
            }
            $compressed = deflate_add($deflate, $chunk, feof($sourceHandle) ? ZLIB_FINISH : ZLIB_NO_FLUSH);
            if ($compressed !== false && $compressed !== '') {
                fwrite($gzHandle, $compressed);
            }
        }

        @fclose($sourceHandle);
        @fclose($gzHandle);

        return file_exists($targetGz) && filesize($targetGz) > 0;
    }

    /**
     * @inheritDoc
     */
    protected function rotate(): void
    {
        parent::rotate();
        $this->cleanupOldFiles();
    }

    public function getMaxFileSize(): int
    {
        return $this->maxFileSize;
    }

    public function isRotateByDate(): bool
    {
        return $this->rotateByDate;
    }

    public function isCompress(): bool
    {
        return $this->compress;
    }
}
