<?php

namespace Pinoox\Component\Template\Frontend;

/**
 * Resolves the JS package manager for theme frontend CLI (dev, build, deps).
 *
 * Set PINOOX_JS_PACKAGE_MANAGER in project .env, or auto-detect from lockfiles in the theme folder.
 */
final class FrontendPackageManager
{
    public const ENV = 'PINOOX_JS_PACKAGE_MANAGER';

    public const NPM = 'npm';

    public const BUN = 'bun';

    public const PNPM = 'pnpm';

    public const YARN = 'yarn';

    public static function name(?string $projectPath = null): string
    {
        $fromEnv = self::envValue();

        if ($fromEnv !== '') {
            return self::normalizeName($fromEnv);
        }

        if ($projectPath !== null && trim($projectPath) !== '') {
            return self::detectFromPath($projectPath);
        }

        return self::NPM;
    }

    public static function binary(?string $projectPath = null): string
    {
        return match (self::name($projectPath)) {
            self::BUN => PHP_OS_FAMILY === 'Windows' ? 'bun.exe' : 'bun',
            self::PNPM => PHP_OS_FAMILY === 'Windows' ? 'pnpm.cmd' : 'pnpm',
            self::YARN => PHP_OS_FAMILY === 'Windows' ? 'yarn.cmd' : 'yarn',
            default => PHP_OS_FAMILY === 'Windows' ? 'npm.cmd' : 'npm',
        };
    }

    /**
     * @return list<string>
     */
    public static function runScriptCommand(string $script): array
    {
        return ['run', $script];
    }

    /**
     * @return list<string>
     */
    public static function installCommand(string $projectPath, bool $preferCi = true): array
    {
        $path = rtrim(str_replace('\\', '/', $projectPath), '/');

        return match (self::name($projectPath)) {
            self::BUN => self::bunInstallArgs($path, $preferCi),
            self::PNPM => self::pnpmInstallArgs($path, $preferCi),
            self::YARN => is_file($path . '/yarn.lock') && $preferCi
                ? ['install', '--frozen-lockfile']
                : ['install'],
            default => self::npmInstallArgs($path, $preferCi),
        };
    }

    /**
     * Lock/manifest files that invalidate node_modules when newer.
     *
     * @return list<string>
     */
    public static function installStampFiles(?string $projectPath = null): array
    {
        return match (self::name($projectPath)) {
            self::BUN => ['bun.lock', 'bun.lockb', 'package.json'],
            self::PNPM => ['pnpm-lock.yaml', 'package.json'],
            self::YARN => ['yarn.lock', 'package.json'],
            default => ['package-lock.json', 'npm-shrinkwrap.json', 'package.json'],
        };
    }

    public static function detectFromPath(string $projectPath): string
    {
        $path = rtrim(str_replace('\\', '/', $projectPath), '/');

        if (is_file($path . '/bun.lock') || is_file($path . '/bun.lockb')) {
            return self::BUN;
        }

        if (is_file($path . '/pnpm-lock.yaml')) {
            return self::PNPM;
        }

        if (is_file($path . '/yarn.lock')) {
            return self::YARN;
        }

        return self::NPM;
    }

    private static function envValue(): string
    {
        $value = getenv(self::ENV);

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        if (isset($_ENV[self::ENV]) && is_string($_ENV[self::ENV]) && trim($_ENV[self::ENV]) !== '') {
            return trim($_ENV[self::ENV]);
        }

        if (isset($_SERVER[self::ENV]) && is_string($_SERVER[self::ENV]) && trim($_SERVER[self::ENV]) !== '') {
            return trim($_SERVER[self::ENV]);
        }

        return '';
    }

    private static function normalizeName(string $value): string
    {
        $value = strtolower(trim($value));

        return match ($value) {
            self::BUN => self::BUN,
            self::PNPM => self::PNPM,
            self::YARN => self::YARN,
            self::NPM => self::NPM,
            default => self::NPM,
        };
    }

    /**
     * @return list<string>
     */
    private static function npmInstallArgs(string $path, bool $preferCi): array
    {
        if ($preferCi && is_file($path . '/package-lock.json')) {
            return ['ci'];
        }

        return ['install'];
    }

    /**
     * @return list<string>
     */
    private static function bunInstallArgs(string $path, bool $preferCi): array
    {
        if ($preferCi && (is_file($path . '/bun.lock') || is_file($path . '/bun.lockb'))) {
            return ['install', '--frozen-lockfile'];
        }

        return ['install'];
    }

    /**
     * @return list<string>
     */
    private static function pnpmInstallArgs(string $path, bool $preferCi): array
    {
        if ($preferCi && is_file($path . '/pnpm-lock.yaml')) {
            return ['install', '--frozen-lockfile'];
        }

        return ['install'];
    }
}
