<?php

namespace Pinoox\Component\Kernel\Debug;

use Pinoox\Component\Kernel\Debug\Support\ExceptionContext;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\ErrorHandler\DebugClassLoader;
use Symfony\Component\ErrorHandler\ErrorHandler;

class PinooxDebug
{
    private static ?ErrorHandler $handler = null;

    public static function isEnabled(): bool
    {
        return self::$handler !== null;
    }

    /**
     * @param bool|null $debug Rich exception page when true; friendly production page when false.
     *                         Defaults to {@see RuntimeMode::bootDebugEnabled()} (PINOOX_EXCEPTION).
     */
    public static function enable(?bool $debug = null): ErrorHandler
    {
        if (self::$handler !== null) {
            return self::$handler;
        }

        $debug ??= \Pinoox\Component\Runtime\RuntimeMode::bootDebugEnabled();

        error_reporting(\E_ALL & ~\E_DEPRECATED & ~\E_USER_DEPRECATED);

        // Never leak PHP's default stack traces to the client.
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');

        if (\in_array(\PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
            ini_set('log_errors', '0');
        }

        @ini_set('zend.assertions', 1);
        ini_set('assert.active', 1);
        ini_set('assert.exception', 1);

        if ($debug) {
            DebugClassLoader::enable();
        }

        $handler = ErrorHandler::register(new ErrorHandler(new BufferingLogger(), $debug));
        $projectDir = defined('PINOOX_BASE_PATH')
            ? rtrim(str_replace('\\', '/', (string) PINOOX_BASE_PATH), '/')
            : ExceptionContext::collect()['project_root'];

        $handler->setExceptionHandler(static function (\Throwable $exception) use ($projectDir, $debug): void {
            if (\in_array(\PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
                fwrite(STDERR, (new PinooxCliErrorRenderer($projectDir))->render($exception));
                exit(255);
            }

            $renderer = new PinooxHtmlErrorRenderer($debug, null, null, $projectDir);
            $exception = $renderer->render($exception);

            if (!headers_sent()) {
                http_response_code($exception->getStatusCode());

                foreach ($exception->getHeaders() as $name => $value) {
                    header($name . ': ' . $value, false);
                }
            }

            echo $exception->getAsString();
            exit(255);
        });

        self::$handler = $handler;

        return $handler;
    }
}

