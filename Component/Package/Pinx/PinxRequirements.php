<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Kernel\Exception;

/**
 * Runtime requirements declared by a Pinx package.
 *
 * The first contract version intentionally supports a small,
 * deterministic requirement set. Unknown requirements fail closed
 * so older or incomplete runtimes never silently ignore them.
 */
final class PinxRequirements
{
    /**
     * First Pinoox kernel code that understands and enforces Pinx requirements.
     */
    public const MIN_KERNEL_CODE = 230;

    private const PHP_CONSTRAINT_PATTERN = '/^>=\s*(\d+\.\d+(?:\.\d+)?)$/';

    /**
     * @param mixed $requirements
     * @return array<string, string>
     */
    public static function normalize(mixed $requirements): array
    {
        if ($requirements === null || $requirements === []) {
            return [];
        }

        if (!is_array($requirements)) {
            throw new Exception('Pinx requirements must be an object.');
        }

        $normalized = [];

        foreach ($requirements as $name => $constraint) {
            if (!is_string($name) || trim($name) === '') {
                throw new Exception('Pinx requirement names must be non-empty strings.');
            }

            $name = strtolower(trim($name));

            if ($name !== 'php') {
                throw new Exception('Unsupported package requirement: ' . $name);
            }

            if (array_key_exists($name, $normalized)) {
                throw new Exception('Duplicate package requirement: ' . $name);
            }

            if (!is_string($constraint) || trim($constraint) === '') {
                throw new Exception(sprintf(
                    'Requirement "%s" must contain a non-empty constraint.',
                    $name,
                ));
            }

            $constraint = trim($constraint);

            if ($name === 'php') {
                self::assertValidPhpConstraint($constraint);
            }

            $normalized[$name] = $constraint;
        }

        return $normalized;
    }

    public static function effectiveMinpin(int $configuredMinpin, mixed $requirements): int
    {
        $normalized = self::normalize($requirements);

        if ($normalized === []) {
            return $configuredMinpin;
        }

        return max($configuredMinpin, self::MIN_KERNEL_CODE);
    }

    /**
     * @return array{
     *     satisfied: bool,
     *     checks: list<array{
     *         name: string,
     *         label: string,
     *         current: string,
     *         required: string,
     *         satisfied: bool,
     *         message: ?string
     *     }>,
     *     errors: list<string>
     * }
     */
    public static function inspect(PinxManifest $manifest, ?string $phpVersion = null): array
    {
        $requirements = self::normalize($manifest->requirementsRaw());
        $phpVersion ??= PHP_VERSION;

        $checks = [];
        $errors = [];

        if (isset($requirements['php'])) {
            $constraint = $requirements['php'];
            $satisfied = self::satisfiesPhp($phpVersion, $constraint);

            $message = $satisfied
                ? null
                : sprintf(
                    'This package requires PHP %s (current: %s).',
                    $constraint,
                    $phpVersion,
                );

            $checks[] = [
                'name' => 'php',
                'label' => 'PHP',
                'current' => $phpVersion,
                'required' => $constraint,
                'satisfied' => $satisfied,
                'message' => $message,
            ];

            if ($message !== null) {
                $errors[] = $message;
            }
        }

        return [
            'satisfied' => $errors === [],
            'checks' => $checks,
            'errors' => $errors,
        ];
    }

    public static function assertSatisfied(PinxManifest $manifest, ?string $phpVersion = null): void
    {
        $result = self::inspect($manifest, $phpVersion);

        if (!$result['satisfied']) {
            throw new Exception(implode(' ', $result['errors']));
        }
    }

    private static function assertValidPhpConstraint(string $constraint): void
    {
        if (preg_match(self::PHP_CONSTRAINT_PATTERN, $constraint) !== 1) {
            throw new Exception(sprintf(
                'Unsupported PHP requirement constraint "%s". Supported format: >=8.3 or >=8.3.0.',
                $constraint,
            ));
        }
    }

    private static function satisfiesPhp(string $currentVersion, string $constraint): bool
    {
        if (preg_match(self::PHP_CONSTRAINT_PATTERN, $constraint, $matches) !== 1) {
            self::assertValidPhpConstraint($constraint);

            return false;
        }

        return version_compare($currentVersion, $matches[1], '>=');
    }
}
