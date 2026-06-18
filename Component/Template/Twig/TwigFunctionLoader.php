<?php

namespace Pinoox\Component\Template\Twig;

final class TwigFunctionLoader
{
    /**
     * @return list<string>
     */
    public static function discoverNames(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $content = file_get_contents($file);
        if ($content === false || $content === '') {
            return [];
        }

        if (preg_match_all('/\bfunction\s+([a-zA-Z_\x7f-\xff][\w\x7f-\xff]*)\s*(?:\(|:)/', $content, $matches) < 1) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }

    public static function loadFile(string $file): void
    {
        if (is_file($file)) {
            require_once $file;
        }
    }

    /**
     * Load a PHP functions file and return global names that are callable at runtime.
     *
     * @return list<string>
     */
    public static function registerableNames(string $file): array
    {
        self::loadFile($file);

        return array_values(array_filter(
            self::discoverNames($file),
            static fn (string $name): bool => function_exists($name) && is_callable($name),
        ));
    }
}
