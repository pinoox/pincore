<?php

use Monolog\Level;
use Pinoox\Component\Log\LogConfig;
use Pinoox\Portal\Logger;

it('declares the Logger portal contract', function () {
    expectPortalContract(Logger::class);
});

it('parses log levels from config strings', function () {
    expect(LogConfig::parseLevel('debug'))->toBe(Level::Debug)
        ->and(LogConfig::parseLevel('error'))->toBe(Level::Error);
});

it('builds channel names with optional suffix', function () {
    $name = LogConfig::channelName('auth');

    expect($name)->toContain('.auth');
});

it('exposes helper functions for logging', function () {
    expect(function_exists('logger'))->toBeTrue()
        ->and(function_exists('log_info'))->toBeTrue()
        ->and(function_exists('log_error'))->toBeTrue()
        ->and(function_exists('log_debug'))->toBeTrue();
});

it('implements psr logger on manager', function () {
    expect(Logger::___())->toBeInstanceOf(\Psr\Log\LoggerInterface::class);
});

it('creates scoped channel loggers', function () {
    $name = Logger::channel('migration')->getMonolog()->getName();

    expect($name)->toContain('migration');
});

it('parses byte strings into integer bytes', function () {
    expect(LogConfig::parseBytes('10MB'))->toBe(10485760)
        ->and(LogConfig::parseBytes('500KB'))->toBe(512000)
        ->and(LogConfig::parseBytes('1GB'))->toBe(1073741824)
        ->and(LogConfig::parseBytes(2048))->toBe(2048)
        ->and(LogConfig::parseBytes(''))->toBe(0)
        ->and(LogConfig::parseBytes(null))->toBe(0);
});

it('resolves production mode default log level to error', function () {
    expect(\Pinoox\Component\Runtime\RuntimeMode::defaultLogLevelForMode('production'))->toBe('error')
        ->and(\Pinoox\Component\Runtime\RuntimeMode::defaultLogLevelForMode('prod'))->toBe('error')
        ->and(\Pinoox\Component\Runtime\RuntimeMode::defaultLogLevelForMode('staging'))->toBe('warning')
        ->and(\Pinoox\Component\Runtime\RuntimeMode::defaultLogLevelForMode('development'))->toBe('debug')
        ->and(\Pinoox\Component\Runtime\RuntimeMode::defaultLogLevelForMode('dev'))->toBe('debug');
});

it('rotates file when size threshold is reached', function () {
    $tempDir = sys_get_temp_dir() . '/pinoox_test_log_' . uniqid();
    @mkdir($tempDir, 0777, true);
    $logFile = $tempDir . '/test.log';

    $handler = new \Pinoox\Component\Log\Handler\SizeRotatingFileHandler(
        filename: $logFile,
        maxFiles: 3,
        level: Level::Debug,
        maxFileSize: 200, // Small threshold for test (200 bytes)
        rotateByDate: false,
        compress: false,
        bubble: true,
        useLocking: false,
    );

    $logger = new \Monolog\Logger('test');
    $logger->pushHandler($handler);

    // Write enough logs to exceed 200 bytes
    $logger->info(str_repeat('A', 150));
    $logger->info(str_repeat('B', 150));

    $rotatedPart = $tempDir . '/test.1.log';
    expect(file_exists($rotatedPart))->toBeTrue();

    $handler->close();
    @unlink($logFile);
    @unlink($rotatedPart);
    @rmdir($tempDir);
});

it('creates size rotating handler by default in LogFactory', function () {
    $tempDir = sys_get_temp_dir() . '/pinoox_test_factory_' . uniqid();
    @mkdir($tempDir, 0777, true);
    $path = $tempDir . '/factory.log';

    $logger = \Pinoox\Component\Log\LogFactory::make('test_channel', [
        'path' => $path,
        'level' => Level::Debug,
        'max_size' => '5MB',
        'max_files' => 5,
        'rotate' => true,
    ]);

    $handlers = $logger->getHandlers();
    expect($handlers)->toHaveCount(1)
        ->and($handlers[0])->toBeInstanceOf(\Pinoox\Component\Log\Handler\SizeRotatingFileHandler::class);

    @rmdir($tempDir);
});

it('compresses rotated log files to gzip when compression is enabled', function () {
    $tempDir = sys_get_temp_dir() . '/pinoox_test_compress_' . uniqid();
    @mkdir($tempDir, 0777, true);
    $logFile = $tempDir . '/compress.log';

    $handler = new \Pinoox\Component\Log\Handler\SizeRotatingFileHandler(
        filename: $logFile,
        maxFiles: 3,
        level: Level::Debug,
        maxFileSize: 150,
        rotateByDate: false,
        compress: true,
        bubble: true,
        useLocking: false,
    );

    $logger = new \Monolog\Logger('compress_test');
    $logger->pushHandler($handler);

    $logger->info(str_repeat('X', 120));
    $logger->info(str_repeat('Y', 120));

    $rotatedGz = $tempDir . '/compress.1.log.gz';
    expect(file_exists($rotatedGz))->toBeTrue();

    $handler->close();
    @unlink($logFile);
    @unlink($rotatedGz);
    @rmdir($tempDir);
});

it('filters out info logs when minimum level is Error', function () {
    $tempDir = sys_get_temp_dir() . '/pinoox_test_level_' . uniqid();
    @mkdir($tempDir, 0777, true);
    $logFile = $tempDir . '/level.log';

    $logger = \Pinoox\Component\Log\LogFactory::make('level_test', [
        'path' => $logFile,
        'level' => Level::Error,
        'max_size' => '10MB',
        'rotate' => false,
    ]);

    $logger->debug('This debug should NOT be written');
    $logger->info('This info should NOT be written');
    $logger->warning('This warning should NOT be written');

    // In error level, file should not even exist or contain no records
    if (file_exists($logFile)) {
        expect(file_get_contents($logFile))->not->toContain('This debug')
            ->and(file_get_contents($logFile))->not->toContain('This info')
            ->and(file_get_contents($logFile))->not->toContain('This warning');
    }

    $logger->error('This error MUST be written');
    expect(file_exists($logFile))->toBeTrue()
        ->and(file_get_contents($logFile))->toContain('This error MUST be written');

    $logger->close();
    @unlink($logFile);
    @rmdir($tempDir);
});

it('creates special handlers for null, errorlog, and deduplication drivers', function () {
    $nullLogger = \Pinoox\Component\Log\LogFactory::make('null_test', [
        'driver' => 'null',
        'path' => '/tmp/null.log',
        'level' => Level::Debug,
    ]);
    expect($nullLogger->getHandlers()[0])->toBeInstanceOf(\Monolog\Handler\NullHandler::class);

    $errorLogger = \Pinoox\Component\Log\LogFactory::make('errorlog_test', [
        'driver' => 'errorlog',
        'path' => '/tmp/error.log',
        'level' => Level::Error,
    ]);
    expect($errorLogger->getHandlers()[0])->toBeInstanceOf(\Monolog\Handler\ErrorLogHandler::class);

    $dedupLogger = \Pinoox\Component\Log\LogFactory::make('dedup_test', [
        'driver' => 'size_daily',
        'path' => sys_get_temp_dir() . '/dedup.log',
        'level' => Level::Error,
        'deduplicate' => true,
    ]);
    expect($dedupLogger->getHandlers()[0])->toBeInstanceOf(\Monolog\Handler\DeduplicationHandler::class);
});

