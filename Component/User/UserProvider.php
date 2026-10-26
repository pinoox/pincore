<?php

namespace Pinoox\Component\User;

use Illuminate\Database\Eloquent\Builder;
use Pinoox\Portal\Hash;
use Pinoox\Model\UserModel;

class UserProvider
{
    /**
     * @param array<string, mixed> $credentials
     */
    public function retrieveByCredentials(array $credentials): ?UserModel
    {
        $login = $credentials['username']
            ?? $credentials['email']
            ?? $credentials['mobile']
            ?? $credentials['login']
            ?? null;

        if ($login === null || $login === '') {
            return null;
        }

        $activeOnly = (bool) ($credentials['active_only'] ?? true);

        return $this->retrieveByLogin((string) $login, $activeOnly);
    }

    public function validateCredentials(UserModel $user, string $password): bool
    {
        $user->makeVisible('password');

        return Hash::check($password, $user->password);
    }

    public function retrieveById(int $userId): ?UserModel
    {
        return UserModel::where('user_id', $userId)->first();
    }

    public function retrieveByLogin(string $identifier, bool $activeOnly = false): ?UserModel
    {
        $normalized = strtr(trim($identifier), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        $query = UserModel::where(function (Builder $builder) use ($identifier, $normalized) {
            $builder->where('username', $normalized)
                ->orWhere('email', $normalized)
                ->orWhere('mobile', $normalized);

            if ($identifier !== $normalized) {
                $builder->orWhere('username', $identifier)
                    ->orWhere('mobile', $identifier);
            }
        });

        if ($activeOnly) {
            $query->where('status', UserModel::ACTIVE);
        }

        return $query->first();
    }
}

