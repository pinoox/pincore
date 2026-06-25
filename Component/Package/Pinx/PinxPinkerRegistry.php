<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Store\Baker\Pinker;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\Pinker as PinkerPortal;
use Pinoox\Support\SystemConfig;

/**
 * Lists and manages Pinker overlay files for an app (app.php + config/**).
 */
final class PinxPinkerRegistry
{
    /**
     * @return array<string, array{label: string, source: string, pinker: Pinker}>
     */
    public static function entries(string $package): array
    {
        if ($package === 'platform') {
            return [];
        }

        $entries = [];
        $base = rtrim(str_replace('\\', '/', AppEngine::path($package)), '/');
        $appFile = SystemConfig::rawPath('app_file', 'app.php');

        if (is_file($base . '/' . $appFile)) {
            $source = $base . '/' . $appFile;
            $pinker = new Pinker($source, PinkerPortal::bakedFileFromSource($source));
            $pinker->dumping(true);
            $entries[$appFile] = [
                'label' => $appFile,
                'source' => $source,
                'pinker' => $pinker,
            ];
        }

        $configFolder = trim(SystemConfig::rawPath('app_config', 'config'), '/\\');
        $configPath = $base . '/' . $configFolder;

        if (is_dir($configPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($configPath, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $source = str_replace('\\', '/', $file->getPathname());
                $label = $configFolder . '/' . ltrim(substr($source, strlen(str_replace('\\', '/', $configPath))), '/');
                $pinker = new Pinker($source, PinkerPortal::bakedFileFromSource($source));
                $entries[$label] = [
                    'label' => $label,
                    'source' => $source,
                    'pinker' => $pinker,
                ];
            }
        }

        ksort($entries);

        return $entries;
    }

    /**
     * @return array<string, array{data: array<string, mixed>, remove: list<string>}>
     */
    public static function snapshotOverrides(string $package): array
    {
        $snapshot = [];

        foreach (self::entries($package) as $label => $entry) {
            $override = self::readOverride($entry['pinker']);

            if ($override === null) {
                continue;
            }

            $snapshot[$label] = $override;
        }

        return $snapshot;
    }

    public static function rebuild(string $package): int
    {
        $count = 0;

        foreach (self::entries($package) as $entry) {
            $entry['pinker']->rebuild();
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string, array{data: array<string, mixed>, remove: list<string>}> $snapshot
     */
    public static function restoreOverrides(string $package, array $snapshot): int
    {
        $paths = 0;

        foreach (self::entries($package) as $label => $entry) {
            if (!isset($snapshot[$label])) {
                continue;
            }

            $data = $snapshot[$label]['data'] ?? [];
            $remove = $snapshot[$label]['remove'] ?? [];

            if ($data === [] && $remove === []) {
                continue;
            }

            $overrideFile = $entry['pinker']->getOverrideFile();

            if ($overrideFile === null) {
                continue;
            }

            self::writeOverrideFile(
                $overrideFile,
                $entry['source'],
                $entry['pinker']->getBakedFile(),
                is_array($data) ? $data : [],
                is_array($remove) ? array_values($remove) : [],
            );

            $paths += count($data) + count($remove);
        }

        return $paths;
    }

    /**
     * @return array{data: array<string, mixed>, remove: list<string>}|null
     */
    private static function readOverride(Pinker $pinker): ?array
    {
        $overrideFile = $pinker->getOverrideFile();

        if ($overrideFile === null || !is_file($overrideFile)) {
            return null;
        }

        $data = include $overrideFile;

        if (!is_array($data) || ($data['__pinker_override__'] ?? false) !== true) {
            return null;
        }

        $sets = $data['data'] ?? [];
        $removes = $data['remove'] ?? [];

        if (!is_array($sets)) {
            $sets = [];
        }

        if (!is_array($removes)) {
            $removes = [];
        }

        if ($sets === [] && $removes === []) {
            return null;
        }

        return [
            'data' => $sets,
            'remove' => array_values($removes),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $remove
     */
    private static function writeOverrideFile(
        string $overrideFile,
        string $source,
        string $cache,
        array $data,
        array $remove,
    ): void {
        $dir = dirname($overrideFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $payload = [
            '__pinker_override__' => true,
            'schema' => 1,
            'data' => $data,
            'remove' => $remove,
            'info' => [
                'source' => $source,
                'cache' => $cache,
                'updated_at' => time(),
            ],
        ];

        $export = var_export($payload, true);
        $content = '<?php' . "\n"
            . '/** Pinoox Baker */' . "\n\n"
            . 'return ' . $export . ';';

        file_put_contents($overrideFile, $content);
    }

    public static function purge(string $package): int
    {
        $count = 0;

        foreach (self::entries($package) as $entry) {
            $entry['pinker']->remove();
            $count++;
        }

        $pinkerRoot = rtrim(str_replace('\\', '/', SystemConfig::path('pinker')), '/');

        foreach ([
            $pinkerRoot . '/apps/' . $package,
            $pinkerRoot . '/state/apps/' . $package,
        ] as $dir) {
            if (is_dir($dir)) {
                \Pinoox\Portal\FileSystem::remove($dir);
            }
        }

        return $count;
    }
}
