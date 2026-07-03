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
    public static function bootUtf8(): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }

        ini_set('default_charset', 'UTF-8');

        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }

        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_cp_set')) {
            @sapi_windows_cp_set(65001);
        }
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
