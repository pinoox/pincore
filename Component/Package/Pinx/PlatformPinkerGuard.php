<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Helpers\Filesystem;

/**
 * Keeps Pinker runtime state valid after a platform zip overwrite.
 *
 * Soft overrides live in pinker/state and may be pruned when source is newer.
 * Durable install data lives in pinker/stable and is never wiped by clean/rebuild.
 * Vendor/app replace updates source mtimes, so state override timestamps must be
 * refreshed after apply. Stable files need no timestamp refresh.
 */
final class PlatformPinkerGuard
{
    /**
     * Host files that must never be replaced from a distribution zip.
     *
     * @return list<string>
     */
    public static function runtimeConfigFiles(): array
    {
        return [
            'platform/app-router.config.php',
            'platform/domain.config.php',
            'platform/apps.config.php',
        ];
    }

    public static function shouldPreserveRuntimeConfig(string $relativePath): bool
    {
        $relativePath = PlatformArchive::normalizeRelative($relativePath);

        foreach (self::runtimeConfigFiles() as $file) {
            if ($relativePath === $file) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the host already has a non-empty runtime config (do not replace from zip).
     * Missing or empty files (e.g. copied pincore stub `return [];`) are not locked.
     */
    public static function hostHasRuntimeConfig(string $projectRoot, string $relativePath): bool
    {
        $file = rtrim(str_replace('\\', '/', $projectRoot), '/')
            . '/' . PlatformArchive::normalizeRelative($relativePath);

        if (!is_file($file)) {
            return false;
        }

        $data = include $file;

        return self::runtimeConfigHasValues($data);
    }

    /**
     * Write pinker/bake copies of runtime configs when bake is missing or empty.
     * Does not overwrite a bake that already has values (live host routing).
     */
    public static function bakeMissingRuntimeConfigs(string $projectRoot): int
    {
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $written = 0;

        foreach (self::runtimeConfigFiles() as $relative) {
            $source = $projectRoot . '/' . $relative;
            if (!is_file($source)) {
                continue;
            }

            $data = include $source;
            if (!is_array($data) || $data === []) {
                continue;
            }

            $bake = $projectRoot . '/pinker/bake/' . $relative;
            if (is_file($bake)) {
                $existing = include $bake;
                if (self::runtimeConfigHasValues($existing)) {
                    continue;
                }
            }

            $dir = dirname($bake);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                continue;
            }

            $mtime = (int) @filemtime($source);
            $size = (int) @filesize($source);
            $hash = @sha1_file($source);
            $export = var_export($data, true);
            $content = "<?php\n"
                . "/**\n"
                . " * Pinoox Baker\n"
                . " * @time {$mtime}\n"
                . " * @schema 2\n"
                . " * @source {$source}\n"
                . " * @source_hash {$hash}\n"
                . " * @source_mtime {$mtime}\n"
                . " * @source_size {$size}\n"
                . " * @env_sensitive no\n"
                . " */\n\n"
                . "return {$export};\n";

            if (@file_put_contents($bake, $content) !== false) {
                $written++;
            }
        }

        return $written;
    }

    private static function runtimeConfigHasValues(mixed $data): bool
    {
        if (!is_array($data) || $data === []) {
            return false;
        }

        if (($data['__pinker_override__'] ?? false) === true) {
            $inner = $data['data'] ?? [];

            return is_array($inner) && $inner !== [];
        }

        foreach ($data as $value) {
            if (is_string($value) && $value !== '') {
                return true;
            }
            if (is_array($value) && $value !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string> relative app path => held directory
     */
    public static function holdAppPinkerDirs(string $projectRoot, array $apps, string $holdRoot): array
    {
        $held = [];
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $holdRoot = rtrim(str_replace('\\', '/', $holdRoot), '/');

        foreach ($apps as $package) {
            if (!is_string($package) || $package === '') {
                continue;
            }

            $pinkerDir = $projectRoot . '/apps/' . $package . '/pinker';

            if (!is_dir($pinkerDir)) {
                continue;
            }

            $target = $holdRoot . '/' . $package . '/pinker';
            Filesystem::copyDirectory($pinkerDir, $target);
            $held[$package] = $target;
        }

        return $held;
    }

    /**
     * @param array<string, string> $held
     */
    public static function restoreAppPinkerDirs(string $projectRoot, array $held): void
    {
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

        foreach ($held as $package => $source) {
            if (!is_dir($source)) {
                continue;
            }

            Filesystem::copyDirectory($source, $projectRoot . '/apps/' . $package . '/pinker');
        }
    }

    /**
     * Mark every pinker/state override as newer than just-applied source files.
     */
    public static function refreshOverrideTimestamps(string $projectRoot): int
    {
        $state = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/pinker/state';

        if (!is_dir($state)) {
            return 0;
        }

        $count = 0;
        $now = time();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($state, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if (!$item->isFile() || strtolower($item->getExtension()) !== 'php') {
                continue;
            }

            if (self::touchOverrideFile($item->getPathname(), $now)) {
                $count++;
            }
        }

        return $count;
    }

    private static function touchOverrideFile(string $file, int $now): bool
    {
        $data = include $file;

        if (!is_array($data) || ($data['__pinker_override__'] ?? false) !== true) {
            return false;
        }

        if (!isset($data['info']) || !is_array($data['info'])) {
            $data['info'] = [];
        }

        $data['info']['updated_at'] = $now;
        $data['schema'] = $data['schema'] ?? 1;
        $data['data'] = is_array($data['data'] ?? null) ? $data['data'] : [];
        $data['remove'] = is_array($data['remove'] ?? null) ? array_values($data['remove']) : [];

        $export = var_export($data, true);
        $content = '<?php' . "\n"
            . '/** Pinoox Baker */' . "\n\n"
            . 'return ' . $export . ';';

        return file_put_contents($file, $content) !== false;
    }
}
