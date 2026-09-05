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

use Pinoox\Model\FileModel;
use Pinoox\Portal\File;
use Pinoox\Portal\Url;

if (!function_exists('file_url')) {
    /**
     * Download URL for a stored file. Detects public vs private disk automatically.
     *
     * @example file_url($fileId)
     * @example file_url($file->hash_id)
     */
    function file_url(int|string|FileModel|null $file): ?string
    {
        return File::url($file);
    }
}

if (!function_exists('file_thumb')) {
    /**
     * Thumbnail URL for a stored image file.
     */
    function file_thumb(int|string|FileModel|null $file): ?string
    {
        return File::thumb($file);
    }
}

if (!function_exists('file_temporary_url')) {
    /**
     * Signed temporary URL for a stored file (S3 native or local dispatcher).
     */
    function file_temporary_url(
        int|string|FileModel|null $file,
        DateTimeInterface|DateInterval|int $expiration,
        bool $thumb = false,
    ): ?string {
        return File::temporaryUrl($file, $expiration, $thumb);
    }
}

if (!function_exists('url_file')) {
    /**
     * Same as file_url(); kept as an alias on the url() family.
     */
    function url_file(int|string|FileModel|null $file): ?string
    {
        return Url::file($file);
    }
}
