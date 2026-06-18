<?php

namespace Pinoox\Component\Translator;

/**
 * Normalize translation values from *.lang.php for PHP/Twig helpers.
 *
 * Structured HTML entries:
 *   'new_articles' => ['html' => true, 'text' => 'مقالات <span>تازه</span>'],
 *   'badge' => ['html' => 'مقالات <span>تازه</span>'],
 */
final class TranslationLine
{
    public static function normalize(mixed $line): string
    {
        if (is_string($line)) {
            return $line;
        }

        if (!is_array($line)) {
            return (string) $line;
        }

        if (isset($line['text']) && is_string($line['text'])) {
            return $line['text'];
        }

        if (isset($line['html']) && is_string($line['html'])) {
            return $line['html'];
        }

        return (string) json_encode($line, JSON_UNESCAPED_UNICODE);
    }

    public static function isHtml(mixed $line): bool
    {
        if (!is_array($line)) {
            return false;
        }

        $html = $line['html'] ?? null;

        if ($html === true || $html === 1 || $html === '1') {
            return true;
        }

        return is_string($html) && $html !== '';
    }
}
