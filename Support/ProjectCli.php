<?php

declare(strict_types=1);

namespace Pinoox\Support;

use Pinoox\Component\Kernel\Loader;
use Pinoox\Component\Server\DevelopmentServer;

/**
 * Resolve project CLI entry scripts and format user-facing command hints.
 *
 * Convention:
 * - pinx package/app workflows → {@see pinxFormat()} (`pinx …` when bin/pinx exists)
 * - platform/pincore workflows → {@see format()} (`php pinoox` or standalone bootstrap)
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
                $lines[] = '  ' . self::format($entry, $root);
                continue;
            }

            $scope = $entry[0] ?? self::SCOPE_PINOOX;
            $command = $entry[1] ?? '';
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
