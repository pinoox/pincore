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

namespace Pinoox\Component\Template;

use Pinoox\Component\Helpers\Str;
use Pinoox\Component\Template\Parser\TemplateNameParser;
use Pinoox\Portal\Mode;

/**
 * Resolves theme templates using engine priority: twig.php → twig → php.
 */
final class TemplateEngineResolver
{
    /**
     * @param callable(string): bool $exists
     */
    public static function resolve(string $name, callable $exists): ?string
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        if (!self::hasKnownExtension($name)) {
            return self::firstExisting(self::candidatesForBase($name), $exists);
        }

        if (self::isLegacyPhpTemplate($name)) {
            $base = substr($name, 0, -strlen('.' . TemplateNameParser::PHP));
            $preferred = self::firstExisting(
                self::candidatesForBase($base, skipPhp: true),
                $exists,
            );

            if ($preferred !== null) {
                self::noticeExplicitPhpRedirect($name, $preferred);

                return $preferred;
            }
        }

        return $exists($name) ? $name : null;
    }

    /**
     * @param callable(string): bool $exists
     */
    public static function exists(string $name, callable $exists): bool
    {
        return self::resolve($name, $exists) !== null;
    }

    /**
     * @param callable(string): bool $exists
     * @return list<string>
     */
    public static function shadowedTemplates(string $baseName, callable $exists): array
    {
        $baseName = self::stripKnownExtension(trim($baseName));
        $matches = [];

        foreach (self::candidatesForBase($baseName) as $candidate) {
            if ($exists($candidate)) {
                $matches[] = $candidate;
            }
        }

        return count($matches) > 1 ? $matches : [];
    }

    /**
     * @param callable(string): bool $exists
     */
    public static function warnShadows(string $requestedName, string $resolvedName, callable $exists): void
    {
        if (!Mode::debug()) {
            return;
        }

        $base = self::stripKnownExtension($requestedName);
        $shadows = self::shadowedTemplates($base, $exists);

        if ($shadows === []) {
            return;
        }

        $ignored = array_values(array_filter(
            $shadows,
            static fn (string $file): bool => $file !== $resolvedName,
        ));

        if ($ignored === []) {
            return;
        }

        trigger_error(
            sprintf(
                'Template "%s" resolves to "%s" (engine order: twig.php → twig → php). Remove unused legacy file(s): %s.',
                $requestedName,
                $resolvedName,
                implode(', ', $ignored),
            ),
            E_USER_NOTICE,
        );
    }

    /**
     * @return list<string>
     */
    private static function candidatesForBase(string $base, bool $skipPhp = false): array
    {
        $candidates = [];

        foreach (TemplateNameParser::ENGINES as $engine) {
            if ($skipPhp && $engine === TemplateNameParser::PHP) {
                continue;
            }

            $candidates[] = $base . '.' . $engine;
        }

        return $candidates;
    }

    /**
     * @param list<string> $candidates
     * @param callable(string): bool $exists
     */
    private static function firstExisting(array $candidates, callable $exists): ?string
    {
        foreach ($candidates as $candidate) {
            if ($exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function hasKnownExtension(string $name): bool
    {
        if (Str::lastHas($name, '.' . TemplateNameParser::TWIG_PHP)) {
            return true;
        }

        return str_ends_with($name, '.' . TemplateNameParser::TWIG)
            || self::isLegacyPhpTemplate($name);
    }

    private static function isLegacyPhpTemplate(string $name): bool
    {
        return str_ends_with($name, '.' . TemplateNameParser::PHP)
            && !Str::lastHas($name, '.' . TemplateNameParser::TWIG_PHP);
    }

    private static function stripKnownExtension(string $name): string
    {
        if (Str::lastHas($name, '.' . TemplateNameParser::TWIG_PHP)) {
            return substr($name, 0, -strlen('.' . TemplateNameParser::TWIG_PHP));
        }

        if (str_ends_with($name, '.' . TemplateNameParser::TWIG)) {
            return substr($name, 0, -strlen('.' . TemplateNameParser::TWIG));
        }

        if (self::isLegacyPhpTemplate($name)) {
            return substr($name, 0, -strlen('.' . TemplateNameParser::PHP));
        }

        return $name;
    }

    private static function noticeExplicitPhpRedirect(string $requested, string $resolved): void
    {
        if (!Mode::debug()) {
            return;
        }

        trigger_error(
            sprintf(
                'Template "%s" redirected to "%s" (twig.php → twig → php). Remove the legacy .php file or update the render() call.',
                $requested,
                $resolved,
            ),
            E_USER_NOTICE,
        );
    }
}
