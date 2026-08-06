<?php

namespace Pinoox\Component\File;

use Pinoox\Component\Transport\TransportConfig;
use Pinoox\Component\Transport\TransportScenario;
use Pinoox\Model\FileModel;
use Pinoox\Portal\App\App;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\Storage;

class FileConfig
{
    public const POLICY_OWNER = 'owner';
    public const POLICY_PUBLIC = 'public';
    public const POLICY_CALLBACK = 'callback';
    public const POLICY_LOGIN = 'login';

    public const HASH_LENGTH_DEFAULT = 8;
    public const HASH_LENGTH_MIN = 4;
    public const HASH_LENGTH_MAX = 50;

    /**
     * @return array{
     *   package: string,
     *   disk: string,
     *   default_access: string,
     *   file_policy: string,
     *   groups: array<string, string>,
     *   hash_length: int,
     *   thumb_width: int,
     *   thumb_height: int
     * }
     */
    public static function resolve(): array
    {
        $disk = (string) (App::get('filesystem.disk')
            ?? App::get('filesystem.default_disk')
            ?? Storage::getDefaultDriver());
        $disk = $disk !== '' ? $disk : 'local';

        // Mode comes from disk only: public disk ⇒ public uploads; anything else ⇒ private.
        $access = $disk === 'public' ? 'public' : 'private';

        return [
            'package' => TransportConfig::package(TransportScenario::FILE_STORAGE),
            'disk' => $disk,
            'default_access' => $access,
            'file_policy' => self::normalizePolicy(App::get('filesystem.file_policy')),
            'groups' => self::normalizeGroups(App::get('filesystem.groups')),
            'hash_length' => self::hashLength(null),
            'thumb_width' => (int) (App::get('filesystem.thumb_width') ?? 512),
            'thumb_height' => (int) (App::get('filesystem.thumb_height') ?? 512),
        ];
    }

    /**
     * Length of generated hash_id (hex). App `filesystem.hash_length`, then filesystems config, then 8.
     */
    public static function hashLength(?int $override = null, ?string $package = null): int
    {
        if ($override !== null) {
            return self::clampHashLength($override);
        }

        if (is_string($package) && $package !== '' && AppEngine::exists($package)) {
            try {
                $fromPackage = AppEngine::config($package)->get('filesystem.hash_length');
                if ($fromPackage !== null && $fromPackage !== '') {
                    return self::clampHashLength((int) $fromPackage);
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        try {
            $fromApp = App::get('filesystem.hash_length');
            if ($fromApp !== null && $fromApp !== '') {
                return self::clampHashLength((int) $fromApp);
            }
        } catch (\Throwable) {
            // fall through
        }

        try {
            $fromGlobal = \Pinoox\Portal\Config::name('~filesystems')->get('hash_length');
            if ($fromGlobal !== null && $fromGlobal !== '') {
                return self::clampHashLength((int) $fromGlobal);
            }
        } catch (\Throwable) {
            // fall through
        }

        return self::HASH_LENGTH_DEFAULT;
    }

    public static function clampHashLength(int $length): int
    {
        return max(self::HASH_LENGTH_MIN, min(self::HASH_LENGTH_MAX, $length));
    }

    public static function policyFor(?string $package = null): string
    {
        if (is_string($package) && $package !== '' && AppEngine::exists($package)) {
            try {
                return self::normalizePolicy(AppEngine::config($package)->get('filesystem.file_policy'));
            } catch (\Throwable) {
                // fall through
            }
        }

        return self::resolve()['file_policy'];
    }

    /**
     * @return array<string, string>
     */
    public static function groupsFor(?string $package = null): array
    {
        if (is_string($package) && $package !== '' && AppEngine::exists($package)) {
            try {
                return self::normalizeGroups(AppEngine::config($package)->get('filesystem.groups'));
            } catch (\Throwable) {
                // fall through
            }
        }

        return self::resolve()['groups'];
    }

    public static function policyForFile(FileModel $file): string
    {
        $group = trim((string) ($file->file_group ?? ''));
        if ($group !== '') {
            $groups = self::groupsFor($file->app);
            if (isset($groups[$group])) {
                return $groups[$group];
            }
        }

        return self::policyFor($file->app);
    }

    /**
     * Disk used for private() uploads (never the public web disk).
     */
    public static function privateDiskName(?array $config = null): string
    {
        $config ??= self::resolve();
        $disk = (string) $config['disk'];

        return $disk === 'public' || $disk === '' ? 'local' : $disk;
    }

    public static function isPublicDisk(?string $disk = null): bool
    {
        $disk ??= self::resolve()['disk'];

        return $disk === 'public';
    }

    public static function normalizePolicy(mixed $value): string
    {
        $policy = strtolower(trim((string) ($value ?? self::POLICY_OWNER)));
        if ($policy === '') {
            return self::POLICY_OWNER;
        }

        if (in_array($policy, ['auth', 'logged_in', 'logged-in', 'islogin', 'is_login'], true)) {
            return self::POLICY_LOGIN;
        }

        return $policy;
    }

    /**
     * @return array<string, string>
     */
    private static function normalizeGroups(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $group => $policy) {
            if (!is_string($group) || $group === '') {
                continue;
            }
            $out[$group] = self::normalizePolicy($policy);
        }

        return $out;
    }
}
