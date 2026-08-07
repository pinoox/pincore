<?php

namespace Pinoox\Component\Deps;

use Pinoox\Support\SystemConfig;

/**
 * Detects npm ci lockfile sync failures and builds actionable fallback warnings.
 */
final class NpmLockSyncHint
{
    /**
     * @param list<string> $outputLines
     */
    public static function isLockOutOfSync(array $outputLines): bool
    {
        $blob = strtolower(implode("\n", $outputLines));

        if (str_contains($blob, 'are in sync')) {
            return true;
        }

        if (str_contains($blob, 'from lock file') && str_contains($blob, 'missing:')) {
            return true;
        }

        if (str_contains($blob, 'eusage') && (
            str_contains($blob, 'package-lock')
            || str_contains($blob, 'npm ci')
            || str_contains($blob, 'clean install')
        )) {
            return true;
        }

        return false;
    }

    /**
     * @param list<string> $outputLines
     * @return list<string>
     */
    public static function warningsForCiFailure(array $outputLines, string $lockPathHint): array
    {
        if (self::isLockOutOfSync($outputLines)) {
            return [
                'warning: npm ci skipped: package.json and package-lock.json are out of sync.',
                'warning: falling back to npm install. Commit the updated package-lock.json after this run:',
                'warning:   ' . $lockPathHint,
            ];
        }

        return [
            'warning: npm ci failed; falling back to npm install.',
        ];
    }

    public static function relativeLockPath(string $targetPath, ?string $projectRoot = null): string
    {
        $lock = rtrim(str_replace('\\', '/', $targetPath), '/') . '/package-lock.json';

        if ($projectRoot === null) {
            try {
                $projectRoot = SystemConfig::rootPath();
            } catch (\Throwable) {
                return $lock;
            }
        }

        $root = rtrim(str_replace('\\', '/', $projectRoot), '/');

        if ($root !== '' && str_starts_with($lock, $root . '/')) {
            return ltrim(substr($lock, strlen($root)), '/');
        }

        return $lock;
    }
}
