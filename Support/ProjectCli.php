<?php

declare(strict_types=1);

namespace Pinoox\Support;

use Pinoox\Component\Kernel\Loader;
use Pinoox\Component\Server\DevelopmentServer;

/**
 * Resolve project CLI entry scripts and format user-facing command hints.
 *
 * Convention (via {@see autoFormat()}):
 * - Single-app projects → `pinx …` when bin/pinx exists, otherwise `php pinoox …`
 * - Multi-app projects → `php pinoox …` (or standalone bootstrap path)
 * - Multi-app workflows (`dev:apps`, `serve`, …) → always platform CLI ({@see format()})
 */
final class ProjectCli
{
    public const DISPLAY_NAME = 'pinoox';

    public const PINX_DISPLAY = 'pinx';

    public const SCOPE_PINX = 'pinx';

    public const SCOPE_PINOOX = 'pinoox';

    public static function root(?string $root = null): string
    {
        if ($root !== null && $root !== '') {
            return rtrim(str_replace('\\', '/', $root), '/');
        }

        if (defined('PINOOX_BASE_PATH')) {
            return rtrim(str_replace('\\', '/', (string) PINOOX_BASE_PATH), '/');
        }

        return rtrim(str_replace('\\', '/', (string) Loader::getBasePath()), '/');
    }

    public static function script(?string $root = null): string
    {
        $root = self::root($root);

        foreach (self::platformCandidates($root) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $root . '/' . self::DISPLAY_NAME;
    }

    public static function pinxScript(?string $root = null): string
    {
        $root = self::root($root);

        foreach (self::pinxCandidates($root) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $root . '/bin/pinx';
    }

    public static function hasPinxBinary(?string $root = null): bool
    {
        $root = self::root($root);

        foreach (self::pinxCandidates($root) as $candidate) {
            if (is_file($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    public static function processCommand(array $arguments, ?string $root = null): array
    {
        return array_merge(
            [DevelopmentServer::phpBinary(), self::script($root)],
            $arguments,
        );
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<string>
     */
    public static function pinxProcessCommand(array $arguments, ?string $root = null): array
    {
        return array_merge(
            [DevelopmentServer::phpBinary(), self::pinxScript($root)],
            $arguments,
        );
    }

    public static function relativeLabel(?string $root = null): string
    {
        return self::relativePath(self::script($root), self::root($root));
    }

    public static function pinxRelativeLabel(?string $root = null): string
    {
        return self::relativePath(self::pinxScript($root), self::root($root));
    }

    /** Platform CLI prefix for docs, errors, and hints. */
    public static function invoke(?string $root = null): string
    {
        $label = self::relativeLabel($root);

        return $label === self::DISPLAY_NAME
            ? 'php ' . self::DISPLAY_NAME
            : 'php ' . $label;
    }

    /** Pinx CLI prefix for package/dev workflows. */
    public static function pinxInvoke(?string $root = null): string
    {
        if (self::hasPinxBinary($root)) {
            return self::PINX_DISPLAY;
        }

        return self::invoke($root);
    }

    public static function format(string $command, ?string $root = null): string
    {
        return self::joinCommand(self::invoke($root), $command);
    }

    public static function pinxFormat(string $command, ?string $root = null): string
    {
        return self::joinCommand(self::pinxInvoke($root), $command);
    }

    /** Always format with the platform CLI (`php pinoox` or launcher bootstrap). */
    public static function platformFormat(string $command, ?string $root = null): string
    {
        return self::format($command, $root);
    }

    public static function isSingleAppProject(?string $root = null): bool
    {
        return DevApp::package($root) !== null;
    }

    /**
     * Hints that must stay on the platform CLI even in single-app / pinx projects.
     */
    public static function isMultiAppHint(string $command): bool
    {
        $command = trim($command);

        if ($command === '') {
            return true;
        }

        if (preg_match('/\b(dev:apps|dev-stack)\b/', $command)) {
            return true;
        }

        if (str_contains($command, '--apps=')) {
            return true;
        }

        if (preg_match('/[a-z][a-z0-9]*_[a-z0-9_]+,\s*[a-z][a-z0-9]*_[a-z0-9_]+/i', $command)) {
            return true;
        }

        if (preg_match('/(?:^|\s)(all|--all)(?:\s|$)/', $command)) {
            return true;
        }

        $first = strtok($command, ' ') ?: $command;

        return in_array($first, ['serve', 'version', 'reset'], true);
    }

    /**
     * @return self::SCOPE_*
     */
    public static function inferScope(string $command, ?string $root = null): string
    {
        if (self::isMultiAppHint($command) || !self::isSingleAppProject($root)) {
            return self::SCOPE_PINOOX;
        }

        return self::SCOPE_PINX;
    }

    /** Pick pinx vs php pinoox from project layout and command intent. */
    public static function autoFormat(string $command, ?string $root = null): string
    {
        return self::suggest(self::inferScope($command, $root), $command, $root);
    }

    /**
     * @param self::SCOPE_* $scope
     */
    public static function suggest(string $scope, string $command, ?string $root = null): string
    {
        return $scope === self::SCOPE_PINX
            ? self::pinxFormat($command, $root)
            : self::format($command, $root);
    }

    /**
     * @param list<array{0: string, 1?: string}|string> $entries scope/command pairs or plain platform commands
     */
    public static function examplesBlock(array $entries, ?string $root = null): string
    {
        $lines = [];

        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $lines[] = '  ' . self::autoFormat($entry, $root);
                continue;
            }

            $scope = $entry[0] ?? null;
            $command = (string) ($entry[1] ?? '');

            if (!is_string($scope) || $scope === '') {
                $lines[] = '  ' . self::autoFormat($command, $root);
                continue;
            }

            $lines[] = '  ' . self::suggest($scope, $command, $root);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{0: string, 1?: string}|string> $exampleEntries
     */
    public static function helpBlock(string $intro, array $exampleEntries, ?string $footer = null, ?string $root = null): string
    {
        $help = rtrim($intro) . "\n\nExamples:\n" . self::examplesBlock($exampleEntries, $root);

        if (is_string($footer) && trim($footer) !== '') {
            $help .= "\n\n" . trim($footer);
        }

        return $help;
    }

    public static function isCliScript(string $script): bool
    {
        $script = str_replace('\\', '/', $script);
        $basename = basename($script);

        if ($basename === self::DISPLAY_NAME || $basename === self::PINX_DISPLAY || $basename === 'pincore') {
            return true;
        }

        if ($basename === 'pinx' && str_contains($script, '/bin/')) {
            return true;
        }

        return $basename === 'bootstrap.php'
            && (str_contains($script, '/platform/launcher/')
                || str_contains($script, '/launcher/'));
    }

    /**
     * @return list<string>
     */
    private static function platformCandidates(string $root): array
    {
        return [
            $root . '/' . self::DISPLAY_NAME,
            $root . '/platform/launcher/bootstrap.php',
            $root . '/launcher/bootstrap.php',
        ];
    }

    /**
     * @return list<string>
     */
    private static function pinxCandidates(string $root): array
    {
        return [
            $root . '/bin/pinx',
            $root . '/vendor/pinoox/pinx-cli/bin/pinx',
        ];
    }

    private static function relativePath(string $absolute, string $root): string
    {
        $absolute = str_replace('\\', '/', $absolute);

        return str_starts_with($absolute, $root . '/')
            ? substr($absolute, strlen($root) + 1)
            : basename($absolute);
    }

    private static function joinCommand(string $invoke, string $command): string
    {
        $command = trim($command);

        return $command === '' ? $invoke : $invoke . ' ' . $command;
    }
}
