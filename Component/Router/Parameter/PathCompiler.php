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

namespace Pinoox\Component\Router\Parameter;

/**
 * Compiles expressive route paths into Symfony-compatible paths, requirements, and defaults.
 *
 * Supported syntax:
 * - {id?}              optional
 * - {path*}            catch-all (.*), default ''
 * - {path*?}           optional catch-all, default null
 * - {id:int}           named type from ParameterPatterns
 * - {status:a|b|c}     enum
 * - /files/{name}.{ext} literal dots between params (already Symfony-safe)
 */
final class PathCompiler
{
    /**
     * @param array<string, string> $filters
     * @param array<string, mixed> $defaults
     * @return array{
     *     path: string,
     *     filters: array<string, string>,
     *     defaults: array<string, mixed>,
     *     score: int,
     *     catch_all: bool,
     * }
     */
    public static function compile(string $path, array $filters = [], array $defaults = []): array
    {
        ParameterPatterns::boot();

        $score = 1000;
        $catchAll = false;
        $compiledFilters = $filters;
        $compiledDefaults = [];

        // Static segments boost specificity (ignore empty bits from leading/trailing slashes).
        $withoutParams = preg_replace('/\{[^}]+\}/', '', $path) ?? $path;
        $staticParts = array_filter(explode('/', trim($withoutParams, '/')), static fn ($p) => $p !== '');
        $score += count($staticParts) * 100;

        $symfonyPath = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(\*)?(\?)?(?::([^}]+))?\}/',
            static function (array $m) use (&$compiledFilters, &$compiledDefaults, &$score, &$catchAll) {
                $name = $m[1];
                $isCatchAll = ($m[2] ?? '') === '*';
                $isOptional = ($m[3] ?? '') === '?';
                $constraint = $m[4] ?? null;

                if ($isCatchAll) {
                    $catchAll = true;
                    $score -= 500;
                    if (!isset($compiledFilters[$name])) {
                        $compiledFilters[$name] = '.*';
                    }
                    if (!array_key_exists($name, $compiledDefaults)) {
                        $compiledDefaults[$name] = $isOptional ? null : '';
                    }
                } elseif ($isOptional) {
                    $score -= 5;
                    if (!array_key_exists($name, $compiledDefaults)) {
                        $compiledDefaults[$name] = null;
                    }
                } else {
                    $score += 10;
                }

                if ($constraint !== null && $constraint !== '' && !isset($compiledFilters[$name])) {
                    if (str_contains($constraint, '|') && !ParameterPatterns::has($constraint)) {
                        // Enum: pending|paid|cancelled
                        $parts = array_map('preg_quote', explode('|', $constraint));
                        $compiledFilters[$name] = implode('|', $parts);
                        $score += 20;
                    } elseif (($pattern = ParameterPatterns::get($constraint)) !== null) {
                        $compiledFilters[$name] = $pattern;
                        $score += 20;
                    } else {
                        // Treat unknown constraint as raw regex (escape-free passthrough).
                        $compiledFilters[$name] = $constraint;
                        $score += 15;
                    }
                } elseif (!$isCatchAll && !$isOptional) {
                    $score += 5;
                }

                return '{' . $name . '}';
            },
            $path,
        );

        if ($symfonyPath === null) {
            $symfonyPath = $path;
        }

        if ($catchAll) {
            $score -= 200;
        }

        return [
            'path' => $symfonyPath,
            'filters' => $compiledFilters,
            'defaults' => array_merge($compiledDefaults, $defaults),
            'score' => $score,
            'catch_all' => $catchAll,
        ];
    }
}
