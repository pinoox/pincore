<?php

namespace Pinoox\Component\Console\Output;

final class RtlText
{
    private const RTL_PATTERN = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}\x{200C}\x{200D}]+/u';
    private const ANSI_PATTERN = '/(\033\[[0-9;?]*[ -\/]*[@-~])/';

    public static function shouldUseVisualOrder($stream = null): bool
    {
        $mode = strtolower((string) ($_SERVER['PINOOX_CLI_RTL'] ?? getenv('PINOOX_CLI_RTL') ?: 'auto'));

        if (in_array($mode, ['0', 'false', 'no', 'off', 'logical'], true)) {
            return false;
        }

        if (in_array($mode, ['1', 'true', 'yes', 'on', 'visual', 'force'], true)) {
            return true;
        }

        return PHP_OS_FAMILY === 'Windows'
            && function_exists('stream_isatty')
            && is_resource($stream)
            && stream_isatty($stream);
    }

    public static function toConsoleVisual(string $text): string
    {
        if ($text === '' || !preg_match(self::RTL_PATTERN, $text)) {
            return $text;
        }

        $parts = preg_split(self::ANSI_PATTERN, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $text;
        }

        foreach ($parts as $index => $part) {
            if ($part === '' || preg_match(self::ANSI_PATTERN, $part)) {
                continue;
            }

            $parts[$index] = preg_replace_callback(
                self::RTL_PATTERN,
                static fn(array $match): string => self::reverse($match[0]),
                $part
            ) ?? $part;
        }

        return implode('', $parts);
    }

    private static function reverse(string $text): string
    {
        preg_match_all('/./us', $text, $characters);

        return implode('', array_reverse($characters[0] ?? []));
    }
}
