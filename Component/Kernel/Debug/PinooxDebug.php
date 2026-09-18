<?php

namespace Pinoox\Component\Kernel\Debug;

use Pinoox\Component\Kernel\Debug\Support\ExceptionContext;
use Pinoox\Component\Runtime\RuntimeMode;
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
     * @param bool|null $appDebug Full debugging and logging mode (APP_DEBUG).
     */
    public static function enable(?bool $debug = null, ?bool $appDebug = null): ErrorHandler
    {
        if (self::$handler !== null) {
            return self::$handler;
        }

        $envMode = RuntimeMode::fromEnv();
        $isProduction = in_array($envMode, [RuntimeMode::PRODUCTION, RuntimeMode::STAGING], true);
        $appDebug ??= (bool) (RuntimeMode::readGlobal()['debug'] ?? false);

        // Rich exception pages are only enabled when debug mode is active and not disabled by PINOOX_EXCEPTION=false
        $pinooxException = $debug ?? RuntimeMode::bootDebugEnabled();
        $renderRichException = $pinooxException && $appDebug;

        // In production or when debug is disabled:
        // 1. Suppress deprecation and notice errors so they never cause disk I/O latency or log file bloat.
        // 2. Disable DebugClassLoader (avoids heavy reflection on class loading).
        // 3. Configure ErrorHandler to only log real errors/warnings/exceptions.
        if ($isProduction || !$appDebug) {
            error_reporting(\E_ALL & ~\E_DEPRECATED & ~\E_USER_DEPRECATED & ~\E_NOTICE & ~\E_USER_NOTICE);
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            ini_set('log_errors', '1');

            @ini_set('zend.assertions', -1);
            @ini_set('assert.active', 0);
            @ini_set('assert.exception', 0);

            $bootLogger = new BufferingLogger();
            $handler = ErrorHandler::register(new ErrorHandler($bootLogger, false));
            // Crucial: Only log actual errors/exceptions; completely ignore deprecations and notices
            $handler->setDefaultLogger($bootLogger, \E_ALL & ~\E_DEPRECATED & ~\E_USER_DEPRECATED & ~\E_NOTICE & ~\E_USER_NOTICE);
        } else {
            // Development mode with debug enabled:
            error_reporting(\E_ALL);
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '1');

            @ini_set('zend.assertions', 1);
            ini_set('assert.active', 1);
            ini_set('assert.exception', 1);

            // DebugClassLoader only in development!
            DebugClassLoader::enable();

            $bootLogger = new BufferingLogger();
            $handler = ErrorHandler::register(new ErrorHandler($bootLogger, true));
            $handler->setDefaultLogger($bootLogger, \E_ALL);
        }

        if (\in_array(\PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
            ini_set('log_errors', '0');
        }

        $projectDir = defined('PINOOX_BASE_PATH')
            ? rtrim(str_replace('\\', '/', (string) PINOOX_BASE_PATH), '/')
            : ExceptionContext::collect()['project_root'];

        $handler->setExceptionHandler(static function (\Throwable $exception) use ($projectDir, $renderRichException, $appDebug): void {
            // Log uncaught exceptions to application logger or error_log
            try {
                if (class_exists(\Pinoox\Portal\Logger::class)) {
                    \Pinoox\Portal\Logger::error($exception->getMessage(), [
                        'exception' => get_class($exception),
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                        'trace' => $appDebug ? $exception->getTraceAsString() : null,
                    ]);
                } else {
                    error_log(sprintf('[Pinoox Exception] %s: %s in %s:%d', get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine()));
                }
            } catch (\Throwable) {
                error_log(sprintf('[Pinoox Exception] %s: %s in %s:%d', get_class($exception), $exception->getMessage(), $exception->getFile(), $exception->getLine()));
            }

            if (\in_array(\PHP_SAPI, ['cli', 'phpdbg', 'embed'], true)) {
                fwrite(STDERR, (new PinooxCliErrorRenderer($projectDir))->render($exception));
                exit(255);
            }

            // Detect JSON / XHR requests and return clean JSON response instead of heavy HTML
            $isJson = (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
                || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

            if ($isJson) {
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=UTF-8');
                }

                $payload = [
                    'status' => false,
                    'message' => $renderRichException ? $exception->getMessage() : 'Internal Server Error',
                ];

                if ($renderRichException) {
                    $payload['exception'] = get_class($exception);
                    $payload['file'] = $exception->getFile();
                    $payload['line'] = $exception->getLine();
                    $payload['trace'] = explode("\n", $exception->getTraceAsString());
                }

                echo json_encode($payload, JSON_UNESCAPED_UNICODE);
                exit(255);
            }

            $renderer = new PinooxHtmlErrorRenderer($renderRichException, null, null, $projectDir);
            $flattened = $renderer->render($exception);

            if (!headers_sent()) {
                http_response_code($flattened->getStatusCode());

                foreach ($flattened->getHeaders() as $name => $value) {
                    header($name . ': ' . $value, false);
                }
            }

            echo $flattened->getAsString();
            exit(255);
        });

        self::$handler = $handler;

        return $handler;
    }
}

