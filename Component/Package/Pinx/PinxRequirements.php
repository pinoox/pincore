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
     *
     * Tracks the current pincore kernel code shipping this contract
     * (config/pincore.config.php), so older kernels never silently
     * ignore a requirements-bearing package.
     */
    public const MIN_KERNEL_CODE = 236;

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
     * Resolve the PHP requirement declared by an app, preferring an explicit
     * declaration in app.php (top-level "requirements" or under "pinx") and
     * falling back to the app's composer.json "require.php" constraint when
     * nothing is declared explicitly.
     *
     * @param array<string, string> $explicit
     * @return array<string, string>
     */
    public static function resolve(array $explicit, string $composerJsonPath): array
    {
        if ($explicit !== []) {
            return $explicit;
        }

        $composer = self::fromComposerJson($composerJsonPath);

        return $composer !== [] ? $composer : [];
    }

    /**
     * Build a requirements map from an app composer.json "require.php".
     *
     * Composer-style constraints (^8.2, ~8.1.0, 8.2.*, >=8.1.2 <8.5, ...)
     * are reduced to their numeric floor, mirroring the launcher
     * pinoox_normalize_php_constraint() semantics. Only the minimum bound
     * is enforceable; that is intentional for this first contract version.
     *
     * @return array<string, string>
     */
    public static function fromComposerJson(string $composerJsonPath): array
    {
        if (!is_file($composerJsonPath)) {
            return [];
        }

        $raw = file_get_contents($composerJsonPath);

        if (!is_string($raw)) {
            return [];
        }

        $composer = json_decode($raw, true);

        if (!is_array($composer)) {
            return [];
        }

        $constraint = $composer['require']['php'] ?? null;

        if (!is_string($constraint) || trim($constraint) === '') {
            return [];
        }

        $floor = self::composerFloor($constraint);

        if ($floor === null) {
            return [];
        }

        return ['php' => '>=' . $floor];
    }

    /**
     * Extract the numeric floor from a composer-style PHP constraint.
     */
    private static function composerFloor(string $constraint): ?string
    {
        $constraint = trim($constraint);

        if ($constraint === '') {
            return null;
        }

        if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $constraint, $matches) !== 1) {
            return null;
        }

        $version = $matches[1];

        if (substr_count($version, '.') === 1) {
            $version .= '.0';
        }

        return $version;
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
