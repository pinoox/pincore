<?php

namespace Pinoox\Component\Template;

final class TemplateHelper
{
    /** @var list<string> */
    private static array $head = [];

    /** @var list<string> */
    private static array $footer = [];

    public static function appendHead(string $html): void
    {
        if ($html !== '') {
            self::$head[] = $html;
        }
    }

    public static function appendFooter(string $html): void
    {
        if ($html !== '') {
            self::$footer[] = $html;
        }
    }

    public static function headHtml(): string
    {
        return implode("\n", self::$head);
    }

    public static function footerHtml(): string
    {
        return implode("\n", self::$footer);
    }

    public static function reset(): void
    {
        self::$head = [];
        self::$footer = [];
    }
}
