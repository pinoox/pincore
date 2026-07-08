<?php

declare(strict_types=1);

namespace Pinoox\Component\Doctor;

use Pinoox\Component\Package\AppManifest;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Support\SystemConfig;

final class DoctorMeta
{
    /**
     * @return array<string, mixed>|null
     */
    public static function forPackage(string $package): ?array
    {
        if ($package === 'platform') {
            return [
                'package' => 'platform',
                'name' => 'Platform',
                'root' => SystemConfig::rootPath(),
                'platform' => true,
            ];
        }

        if (!AppEngine::exists($package)) {
            return [
                'package' => $package,
                'name' => $package,
                'root' => SystemConfig::rootPath(),
                'platform' => false,
            ];
        }

        $config = AppManifest::load($package);

        return [
            'package' => $package,
            'name' => AppManifest::displayName($package),
            'root' => AppEngine::path($package),
            'platform' => false,
            'platform_root' => SystemConfig::rootPath(),
            'theme' => (string) ($config['theme'] ?? 'default'),
            'version' => (string) ($config['version-name'] ?? '1.0.0'),
            'version_code' => (int) ($config['version-code'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function forProject(DoctorProject $project): ?array
    {
        if ($project->isSingleApp()) {
            $config = is_file($project->root . '/app.php') ? (require $project->root . '/app.php') : [];
            $config = is_array($config) ? $config : [];

            return [
                'package' => $project->package,
                'name' => (string) ($config['name'] ?? $project->package),
                'root' => $project->root,
                'platform' => false,
                'theme' => (string) ($config['theme'] ?? 'default'),
                'version' => (string) ($config['version-name'] ?? '1.0.0'),
                'version_code' => (int) ($config['version-code'] ?? 0),
            ];
        }

        return self::forPackage($project->package);
    }
}
