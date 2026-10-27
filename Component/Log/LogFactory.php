<?php

namespace Pinoox\Component\Log;

use Monolog\Handler\BufferHandler;
use Monolog\Handler\DeduplicationHandler;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Logger as MonologLogger;
use Pinoox\Component\Log\Handler\SizeRotatingFileHandler;

class LogFactory
{
    /**
     * @param array{
     *     path: string,
     *     level: \Monolog\Level,
     *     rotate?: bool,
     *     max_files?: int,
     *     max_size?: int|string,
     *     compress?: bool,
     *     buffer?: bool,
     *     deduplicate?: bool,
     *     deduplicate_time?: int,
     *     driver?: string
     * } $config
     */
    public static function make(string $channel, array $config): MonologLogger
    {
        $logger = new MonologLogger($channel);

        $handler = self::createHandler($channel, $config);

        // Deduplication wrapper (suppress repeated error cascades in high traffic)
        if (!empty($config['deduplicate'])) {
            $dedupStore = dirname($config['path']) . '/.dedup_' . md5($channel) . '.log';
            $handler = new DeduplicationHandler(
                $handler,
                $dedupStore,
                $config['level'],
                (int) ($config['deduplicate_time'] ?? 60),
            );
        } elseif (!empty($config['buffer'])) {
            // Memory buffering: flush in batch at script end to minimize disk I/O
            $handler = new BufferHandler($handler, 0, $config['level']);
        }

        $logger->pushHandler($handler);
        $logger->pushProcessor(new ContextProcessor());

        return $logger;
    }

    /**
     * Create base handler according to driver and rotation configuration.
     *
     * @param array<string, mixed> $config
     */
    public static function createHandler(string $channel, array $config): HandlerInterface
    {
        $driver = strtolower((string) ($config['driver'] ?? ''));
        $level = $config['level'];
        $path = (string) ($config['path'] ?? '');
        $maxFiles = max(1, (int) ($config['max_files'] ?? 14));
        $maxSize = LogConfig::parseBytes($config['max_size'] ?? '10MB');
        $compress = (bool) ($config['compress'] ?? false);
        $rotate = (bool) ($config['rotate'] ?? true);

        // Discard all logs
        if ($driver === 'null' || $driver === 'none') {
            return new NullHandler();
        }

        // Standard PHP error_log / stderr (ideal for containers / Docker)
        if ($driver === 'errorlog') {
            return new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, $level);
        }

        // System syslog
        if ($driver === 'syslog') {
            return new SyslogHandler($channel, \LOG_USER, $level);
        }

        // Single file without size limits
        if ($driver === 'single' && $maxSize <= 0) {
            return new StreamHandler($path, $level, true, 0644, true);
        }

        // If size cap is disabled and only daily rotation requested
        if ($maxSize <= 0 && $rotate && $driver !== 'size') {
            return new RotatingFileHandler($path, $maxFiles, $level, true, 0644, true);
        }

        // Default: Size-aware rotating handler (prevents files from blowing up to 500MB)
        return new SizeRotatingFileHandler(
            filename: $path,
            maxFiles: $maxFiles,
            level: $level,
            maxFileSize: $maxSize,
            rotateByDate: $rotate && $driver !== 'size',
            compress: $compress,
            bubble: true,
            filePermission: 0644,
            useLocking: true,
        );
    }
}
