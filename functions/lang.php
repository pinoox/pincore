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

use Pinoox\Component\Helpers\Str;
use Pinoox\Portal\Lang;

if (!function_exists('lang')) {
    function lang($key, array $replace = [], $locale = NULL, $fallback = true)
    {
        $result = Lang::get($key, $replace, $locale, $fallback);
        echo !is_array($result) ? $result : Str::encodeJson($result);
    }
}

if (!function_exists('t')) {
    function t($key, array $replace = [], $locale = NULL, $fallback = true)
    {
        return Lang::get($key, $replace, $locale, $fallback);
    }
}

if (!function_exists('th')) {
    /**
     * Translation helper for strings that contain HTML (Twig registers th as html-safe).
     */
    function th($key, array $replace = [], $locale = NULL, $fallback = true): string
    {
        $line = Lang::get($key, $replace, $locale, $fallback);

        return is_string($line) ? $line : (string) json_encode($line);
    }
}

