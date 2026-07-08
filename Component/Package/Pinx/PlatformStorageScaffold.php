<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Kernel\Exception;
use Symfony\Component\Process\Process;

/**
 * Prepare a clean storage skeleton for platform distribution archives.
 */
final class PlatformStorageScaffold
{
    /** @var list<string> */
    public const DEFAULT_SUBDIRS = [
        'logs',
        'sessions',
        'apps',
        'pinion',
        'chunk-uploads',
        'manager',
        'packages',
    ];

    /** @var list<string> */
    private const SKELETON_FILENAMES = [
        '.htaccess',
        '.gitkeep',
        'web.config',
        'nginx.conf',
        'Caddyfile',
    ];

    public static function workspaceDir(string $projectRoot): string
    {
        return PlatformBuildConfig::buildPath($projectRoot, PlatformBuildConfig::SKELETON_DIR);
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    public static function prepare(string $projectRoot, ?string $targetDir = null): array
    {
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $targetDir ??= self::workspaceDir($projectRoot);
        $targetDir = rtrim(str_replace('\\', '/', $targetDir), '/');

        self::resetDirectory($targetDir);

        $files = [];

        foreach (self::sourceSkeletonFiles($projectRoot) as $relativePath => $absolutePath) {
            $targetPath = $targetDir . '/' . $relativePath;
            $targetParent = dirname($targetPath);

            if (!is_dir($targetParent)) {
                mkdir($targetParent, 0777, true);
            }

            if (!copy($absolutePath, $targetPath)) {
                throw new Exception('Failed to copy storage skeleton file: ' . $relativePath);
            }

            $files[$relativePath] = $targetPath;
        }

        foreach (self::DEFAULT_SUBDIRS as $subdir) {
            $relativePath = $subdir . '/.gitkeep';
            $targetPath = $targetDir . '/' . $relativePath;

            if (is_file($targetPath)) {
                $files[$relativePath] = $targetPath;
                continue;
            }

            if (!is_dir(dirname($targetPath))) {
                mkdir(dirname($targetPath), 0777, true);
            }

            if (file_put_contents($targetPath, '') === false) {
                throw new Exception('Failed to create storage subdirectory marker: ' . $relativePath);
            }

            $files[$relativePath] = $targetPath;
        }

        ksort($files);

        return $files;
    }

    /**
     * @return array<string, string>
     */
    public static function sourceSkeletonFiles(string $projectRoot): array
    {
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $files = [];

        foreach (self::gitTrackedStorageFiles($projectRoot) as $relativePath) {
            $absolutePath = $projectRoot . '/' . $relativePath;

            if (is_file($absolutePath)) {
                $files[ltrim(str_replace('\\', '/', substr($relativePath, strlen('storage/'))), '/')] = $absolutePath;
            }
        }

        $storageRoot = $projectRoot . '/storage';

        if (is_dir($storageRoot)) {
            foreach (self::SKELETON_FILENAMES as $filename) {
                $absolutePath = $storageRoot . '/' . $filename;

                if (is_file($absolutePath)) {
                    $files[$filename] = $absolutePath;
                }
            }
        }

        if ($files === []) {
            $template = self::templateFile($projectRoot);

            if (is_file($template)) {
                $files['.htaccess'] = $template;
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function gitTrackedStorageFiles(string $projectRoot): array
    {
        $process = new Process(['git', '-C', $projectRoot, 'ls-files', 'storage'], $projectRoot);
        $process->run();

        if (!$process->isSuccessful()) {
            return [];
        }

        $lines = preg_split('/\R+/', trim($process->getOutput())) ?: [];

        return array_values(array_filter(array_map('trim', $lines)));
    }

    private static function templateFile(string $projectRoot): ?string
    {
        $candidates = [
            $projectRoot . '/storage/.htaccess',
            $projectRoot . '/vendor/pinoox/pincore/storage/.htaccess',
            $projectRoot . '/pincore/storage/.htaccess',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function resetDirectory(string $path): void
    {
        self::removeDirectory($path);

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new Exception('Failed to create storage scaffold directory: ' . $path);
        }
    }

    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath) && !is_link($fullPath)) {
                self::removeDirectory($fullPath);
                continue;
            }

            @unlink($fullPath);
        }

        @rmdir($path);
    }
}
