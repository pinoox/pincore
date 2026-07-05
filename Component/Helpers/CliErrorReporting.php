<?php

namespace Pinoox\Component\Helpers;

use Pinoox\Component\Kernel\Debug\PinooxDebug;
use Pinoox\Component\Runtime\RuntimeMode;

/**
 * Enable Pinoox exception rendering in CLI without PHP warning noise.
 */
final class CliErrorReporting
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (!\in_array(PHP_SAPI, ['cli', 'phpdbg', 'embed'], true) || self::$booted) {
            return;
        }

        self::$booted = true;
        self::quietPhpOutput();

        if (!self::shouldDisplayErrors()) {
            return;
        }

        error_reporting(self::errorReportingLevel());

        if (RuntimeMode::bootDebugEnabled() && !PinooxDebug::isEnabled()) {
            PinooxDebug::enable();
        }
    }

    /**
     * @return list<string>
     */
    public static function quietPhpIniArgs(): array
    {
        return [
            '-d', 'display_errors=0',
            '-d', 'display_startup_errors=0',
            '-d', 'log_errors=0',
        ];
    }

    public static function shouldDisplayErrors(): bool
    {
        if (!self::envBool('PINOOX_EXCEPTION', true)) {
            return false;
        }

        $mode = RuntimeMode::fromEnv();

        if (\in_array($mode, [RuntimeMode::DEVELOPMENT, RuntimeMode::TEST], true)) {
            return self::envBool('APP_DEBUG', true);
        }

        return self::envBool('APP_DEBUG', RuntimeMode::defaultDebugForMode($mode));
    }

    public static function quietPhpOutput(): void
    {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '0');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (\in_array($severity, [E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }

            return true;
        }, self::errorReportingLevel());
    }

    private static function errorReportingLevel(): int
    {
        return \E_ALL & ~\E_DEPRECATED & ~\E_USER_DEPRECATED;
    }

    private static function envBool(string $key, bool $default): bool
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        if (\is_bool($value)) {
            return $value;
        }

        return filter_var((string) $value, \FILTER_VALIDATE_BOOL);
    }
}
