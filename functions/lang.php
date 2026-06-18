<?php

/**
 *      ****  *  *     *  ****  ****  *    *
 *      *  *  *  * *   *  *  *  *  *   *  *
 *      ****  *  *  *  *  *  *  *  *    *
 *      *     *  *   * *  *  *  *  *   *  *
 *      *     *  *    **  ****  ****  *    *
 * @author   Pinoox
 * @link https://www.pinoox.com/
 * @license  https://opensource.org/licenses/MIT MIT License
 */

use Pinoox\Component\Translator\TranslationLine;
use Pinoox\Portal\Lang;

if (!function_exists('translation_line')) {
    function translation_line(mixed $line): string
    {
        return TranslationLine::normalize($line);
    }
}

if (!function_exists('lang')) {
    /**
     * Echo a translation line (PHP templates). HTML is output as-is — same as legacy lang().
     */
    function lang($key, array $replace = [], $locale = NULL, $fallback = true): void
    {
        $result = Lang::get($key, $replace, $locale, $fallback);
        echo is_array($result) ? translation_line($result) : (string) $result;
    }
}

if (!function_exists('t')) {
    /**
     * Return a translation line for Twig/PHP.
     *
     * Plain strings and structured entries are normalized to text.
     * In Twig, HTML is auto-escaped — use t_html()/th() or |raw only when the string is trusted.
     */
    function t($key, array $replace = [], $locale = NULL, $fallback = true): string
    {
        $line = Lang::get($key, $replace, $locale, $fallback);

        return is_array($line) ? translation_line($line) : (string) $line;
    }
}

if (!function_exists('t_html')) {
    /**
     * Translation helper for lines that contain HTML (registered html-safe in Twig).
     *
     * @example t_html('front.new_articles')
     * @example In Twig: {{ t_html('front.new_articles') }}
     */
    function t_html($key, array $replace = [], $locale = NULL, $fallback = true): string
    {
        return t($key, $replace, $locale, $fallback);
    }
}

if (!function_exists('th')) {
    /** @deprecated Use t_html() — alias kept for backward compatibility */
    function th($key, array $replace = [], $locale = NULL, $fallback = true): string
    {
        return t_html($key, $replace, $locale, $fallback);
    }
}
