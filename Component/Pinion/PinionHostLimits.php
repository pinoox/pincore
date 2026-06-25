<?php

namespace Pinoox\Component\Pinion;

use Pinoox\Pinion\Config as PinionPackageConfig;

/**
 * Detect PHP / host upload limits and tune Pinion chunking for the environment.
 */
final class PinionHostLimits
{
    private const MULTIPART_OVERHEAD = 262144;

    private const DEFAULT_CHUNK = 5 * 1024 * 1024;

    private const DEFAULT_THRESHOLD = 8 * 1024 * 1024;

    /**
     * @return array<string, int|bool>
     */
    public static function inspect(?string $stagingPath = null): array
    {
        $uploadMax = self::iniBytes('upload_max_filesize');
        $postMax = self::iniBytes('post_max_size');
        $memoryLimit = self::iniBytes('memory_limit');
        $maxExecution = (int) ini_get('max_execution_time');

        $safePostPayload = self::safePostPayload($postMax);
        $safeUpload = $uploadMax > 0 ? $uploadMax : PHP_INT_MAX;
        $safePost = $postMax > 0 ? $safePostPayload : PHP_INT_MAX;
        $safeSingle = (int) min($safeUpload, $safePost);

        if ($safeSingle <= 0 || $safeSingle === PHP_INT_MAX) {
            $safeSingle = self::DEFAULT_THRESHOLD;
        }

        $minChunk = self::resolveMinChunk($postMax);
        $recommendedChunk = self::resolveChunkSize($safePostPayload, $minChunk);
        $maxChunk = self::resolveMaxChunk($safePostPayload, $recommendedChunk, $minChunk);
        $pinionThreshold = self::resolvePinionThreshold($safeSingle);
        $parallel = self::resolveParallel($postMax, $memoryLimit, $maxExecution);

        $maxFileSize = self::DEFAULT_CHUNK * 400;
        if ($stagingPath !== null && $stagingPath !== '') {
            $diskCap = self::diskCapBytes(dirname($stagingPath));
            if ($diskCap > 0) {
                $maxFileSize = min($maxFileSize, $diskCap);
            }
        }

        return [
            'upload_max_size' => $uploadMax,
            'post_max_size' => $postMax,
            'memory_limit' => $memoryLimit,
            'max_execution_time' => $maxExecution,
            'safe_single_upload' => $safeSingle,
            'safe_chunk_max' => $maxChunk,
            'pinion_threshold' => $pinionThreshold,
            'chunk_size' => $recommendedChunk,
            'min_chunk_size' => $minChunk,
            'max_chunk_size' => $maxChunk,
            'parallel' => $parallel,
            'max_file_size' => $maxFileSize,
            'direct_upload_enabled' => $safeSingle >= 256 * 1024,
            'pinion_recommended' => $postMax > 0 && $postMax < 16 * 1024 * 1024,
        ];
    }

    /**
     * Merge host-aware limits into pinion config (respects explicit env overrides).
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function tune(array $config, ?string $stagingPath = null): array
    {
        $limits = self::inspect($stagingPath);
        $config['host_limits'] = $limits;

        if (!self::envIsSet('PINION_CHUNK_SIZE')) {
            $config['chunk_size'] = $limits['chunk_size'];
        }

        $config['min_chunk_size'] = min(
            (int) ($config['min_chunk_size'] ?? $limits['min_chunk_size']),
            (int) $limits['chunk_size'],
        );

        if (!self::envIsSet('PINION_CHUNK_SIZE')) {
            $config['max_chunk_size'] = (int) $limits['max_chunk_size'];
        } else {
            $config['max_chunk_size'] = min(
                (int) ($config['max_chunk_size'] ?? $limits['max_chunk_size']),
                (int) $limits['max_chunk_size'],
            );
        }

        if (!self::envIsSet('PINION_MAX_FILE')) {
            $configuredMax = (int) ($config['max_file_size'] ?? 0);
            $config['max_file_size'] = $configuredMax > 0
                ? min($configuredMax, (int) $limits['max_file_size'])
                : (int) $limits['max_file_size'];
        }

        return $config;
    }

    /**
     * @return array<string, int|bool>
     */
    public static function clientProfile(?string $stagingPath = null): array
    {
        $limits = self::inspect($stagingPath);

        return [
            'upload_max_size' => (int) $limits['upload_max_size'],
            'post_max_size' => (int) $limits['post_max_size'],
            'memory_limit' => (int) $limits['memory_limit'],
            'max_execution_time' => (int) $limits['max_execution_time'],
            'safe_single_upload' => (int) $limits['safe_single_upload'],
            'pinion_threshold' => (int) $limits['pinion_threshold'],
            'chunk_size' => (int) $limits['chunk_size'],
            'parallel' => (int) $limits['parallel'],
            'max_file_size' => (int) $limits['max_file_size'],
            'direct_upload_enabled' => (bool) $limits['direct_upload_enabled'],
            'pinion_recommended' => (bool) $limits['pinion_recommended'],
        ];
    }

    private static function safePostPayload(int $postMax): int
    {
        if ($postMax <= 0) {
            return PHP_INT_MAX;
        }

        return max(0, $postMax - self::MULTIPART_OVERHEAD);
    }

    private static function resolveMinChunk(int $postMax): int
    {
        if ($postMax > 0 && $postMax < 2 * 1024 * 1024) {
            return 256 * 1024;
        }

        if ($postMax > 0 && $postMax < 8 * 1024 * 1024) {
            return 512 * 1024;
        }

        return 1024 * 1024;
    }

    private static function resolveChunkSize(int $safePostPayload, int $minChunk): int
    {
        if ($safePostPayload <= 0 || $safePostPayload === PHP_INT_MAX) {
            return self::DEFAULT_CHUNK;
        }

        $target = (int) floor($safePostPayload * 0.85);
        $target = min(self::DEFAULT_CHUNK, $target);
        $target = max($minChunk, $target);

        return PinionPackageConfig::normalizeChunkSize($target, [
            'chunk_size' => $target,
            'min_chunk_size' => $minChunk,
            'max_chunk_size' => max($target, self::DEFAULT_CHUNK * 2),
        ]);
    }

    private static function resolveMaxChunk(int $safePostPayload, int $recommendedChunk, int $minChunk): int
    {
        if ($safePostPayload <= 0 || $safePostPayload === PHP_INT_MAX) {
            return 10 * 1024 * 1024;
        }

        $cap = (int) floor($safePostPayload * 0.92);

        return max($recommendedChunk, max($minChunk, $cap));
    }

    private static function resolvePinionThreshold(int $safeSingle): int
    {
        if ($safeSingle <= 0 || $safeSingle === PHP_INT_MAX) {
            return self::DEFAULT_THRESHOLD;
        }

        if ($safeSingle <= 2 * 1024 * 1024) {
            return max(256 * 1024, (int) floor($safeSingle * 0.75));
        }

        $threshold = (int) floor($safeSingle * 0.9);

        return max(1024 * 1024, min($threshold, self::DEFAULT_THRESHOLD * 4));
    }

    private static function resolveParallel(int $postMax, int $memoryLimit, int $maxExecution): int
    {
        if ($postMax > 0 && $postMax < 4 * 1024 * 1024) {
            return 1;
        }

        if ($memoryLimit > 0 && $memoryLimit < 128 * 1024 * 1024) {
            return 1;
        }

        if ($maxExecution > 0 && $maxExecution < 60) {
            return 1;
        }

        if ($postMax <= 0 || $postMax >= 32 * 1024 * 1024) {
            return 3;
        }

        return 2;
    }

    private static function iniBytes(string $key): int
    {
        $raw = ini_get($key);

        if ($raw === false || $raw === '' || $raw === '-1') {
            return 0;
        }

        return PinionPackageConfig::parseSize($raw);
    }

    private static function diskCapBytes(string $path): int
    {
        if ($path === '' || !is_dir($path)) {
            return 0;
        }

        $free = @disk_free_space($path);

        if ($free === false || $free <= 0) {
            return 0;
        }

        return (int) floor($free * 0.85);
    }

    private static function envIsSet(string $key): bool
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return $value !== false && $value !== null && $value !== '';
    }
}
