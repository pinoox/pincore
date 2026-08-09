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

namespace Pinoox\Component\Helpers;

use Pinoox\Component\Console\Output\RtlText;
use Pinoox\Component\Console\Output\WindowsRtlConsoleOutput;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleApplication
{
    private const UTF8_CODEPAGE = 65001;

    public static function bootUtf8(): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }

        ini_set('default_charset', 'UTF-8');

        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }

        self::ensureUtf8();
    }

    public static function ensureUtf8(): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || !function_exists('sapi_windows_cp_set')) {
            return;
        }

        if (self::isUtf8Console()) {
            return;
        }

        @sapi_windows_cp_set(self::UTF8_CODEPAGE);
    }

    public static function isUtf8Console(): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return true;
        }

        if (function_exists('sapi_windows_cp_is_utf8')) {
            return sapi_windows_cp_is_utf8();
        }

        return function_exists('sapi_windows_cp_get')
            && sapi_windows_cp_get() === self::UTF8_CODEPAGE;
    }

    public static function prefersAsciiUi(): bool
    {
        $unicode = strtolower((string) (getenv('PINOOX_CLI_UNICODE') ?: ($_SERVER['PINOOX_CLI_UNICODE'] ?? '')));
        if (in_array($unicode, ['1', 'true', 'yes', 'on'], true)) {
            return false;
        }

        if (in_array($unicode, ['0', 'false', 'no', 'off'], true)) {
            return true;
        }

        $ascii = strtolower((string) (getenv('PINOOX_CLI_ASCII') ?: ($_SERVER['PINOOX_CLI_ASCII'] ?? '')));
        if (in_array($ascii, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        self::ensureUtf8();

        // Windows Terminal renders Unicode reliably; classic/OEM and many IDE hosts do not.
        return getenv('WT_SESSION') === false || getenv('WT_SESSION') === '';
    }

    public static function addCommand(Application $application, object $command): void
    {
        if (method_exists($application, 'addCommand')) {
            $application->addCommand($command);

            return;
        }

        $application->add($command);
    }

    public static function output(): ?OutputInterface
    {
        $stream = defined('STDOUT') ? STDOUT : null;

        if (!RtlText::shouldUseVisualOrder($stream)) {
            return null;
        }

        return new WindowsRtlConsoleOutput();
    }
}
