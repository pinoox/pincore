<?php

namespace Pinoox\Component\File;

use Closure;
use Pinoox\Model\FileModel;
use Pinoox\Model\UserModel;
use Pinoox\Portal\Access;
use Pinoox\Portal\Auth;

/**
 * Evaluates filesystem.file_policy / filesystem.groups rules for private files.
 */
class FilePolicy
{
    public static function allows(FileModel $file, mixed $user, ?Closure $callback = null): bool
    {
        // Public web disk / public visibility → open
        $disk = FileStorage::resolveDisk($file);
        $access = strtolower((string) ($file->file_access ?? ''));
        if ($disk === 'public' || $access === 'public') {
            return true;
        }

        if ($callback instanceof Closure) {
            return (bool) $callback($file, $user);
        }

        return self::evaluate(FileConfig::policyForFile($file), $file, $user);
    }

    public static function evaluate(string $policy, FileModel $file, mixed $user): bool
    {
        $policy = FileConfig::normalizePolicy($policy);

        return match (true) {
            $policy === FileConfig::POLICY_PUBLIC => true,
            $policy === FileConfig::POLICY_CALLBACK => false,
            $policy === FileConfig::POLICY_LOGIN => Auth::check() && $user !== null,
            $policy === FileConfig::POLICY_OWNER => self::isOwner($file, $user),
            str_starts_with($policy, 'role:') || str_starts_with($policy, 'roles:') => self::hasAnyRole($user, self::listAfterPrefix($policy)),
            str_starts_with($policy, 'permission:') || str_starts_with($policy, 'permissions:') => self::hasAnyPermission($user, self::listAfterPrefix($policy)),
            default => self::isOwner($file, $user),
        };
    }

    private static function isOwner(FileModel $file, mixed $user): bool
    {
        if (!Auth::check() || $user === null) {
            return false;
        }

        $userId = $user instanceof UserModel ? (int) $user->user_id : (int) Auth::id();

        return $userId > 0 && (int) $file->user_id === $userId;
    }

    /**
     * @param list<string> $roles
     */
    private static function hasAnyRole(mixed $user, array $roles): bool
    {
        if ($roles === [] || !Auth::check() || !$user instanceof UserModel) {
            return false;
        }

        if (!empty($user->group_key) && in_array((string) $user->group_key, $roles, true)) {
            return true;
        }

        if (!method_exists($user, 'roles')) {
            return false;
        }

        try {
            return $user->roles()->whereIn('role_key', $roles)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param list<string> $permissions
     */
    private static function hasAnyPermission(mixed $user, array $permissions): bool
    {
        if ($permissions === [] || !Auth::check()) {
            return false;
        }

        try {
            return Access::can($permissions, $user, false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private static function listAfterPrefix(string $policy): array
    {
        $parts = explode(':', $policy, 2);
        $raw = $parts[1] ?? '';

        return array_values(array_filter(array_map(
            static fn (string $v): string => trim($v),
            explode(',', $raw)
        )));
    }
}
