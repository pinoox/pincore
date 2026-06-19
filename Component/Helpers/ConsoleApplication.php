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

use Symfony\Component\Console\Application;

final class ConsoleApplication
{
    public static function addCommand(Application $application, object $command): void
    {
        if (method_exists($application, 'addCommand')) {
            $application->addCommand($command);

            return;
        }

        $application->add($command);
    }
}
