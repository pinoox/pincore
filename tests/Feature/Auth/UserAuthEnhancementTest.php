<?php

use Pinoox\Component\User\Guard;
use Pinoox\Component\User\LoginResult;
use Pinoox\Component\User\Manager;
use Pinoox\Component\User\UserProvider;
use Pinoox\Model\UserModel;
use Pinoox\Portal\Hash;

class TestEnhancementUserProvider extends UserProvider
{
    public ?UserModel $userToReturn = null;
    public bool $validateResult = true;
    public array $lastValidated = [];
    public ?string $lastLoginIdentifier = null;

    public function retrieveByLogin(string $identifier, bool $activeOnly = false): ?UserModel
    {
        $this->lastLoginIdentifier = $identifier;
        return $this->userToReturn;
    }

    public function validateCredentials(UserModel $user, string $password): bool
    {
        $this->lastValidated = ['user' => $user, 'password' => $password];
        return $this->validateResult;
    }
}

class TestEnhancementGuard extends Guard
{
    public bool $isLoggedIn = false;
    public bool $logoutCalled = false;
    public bool $loginCalled = false;
    public ?UserModel $loggedInUser = null;
    public string $tokenToReturn = 'test-token-xyz';

    public function boot(bool $refresh = false): void
    {
    }

    public function check(): bool
    {
        return $this->isLoggedIn;
    }

    public function logout(): void
    {
        $this->logoutCalled = true;
        $this->isLoggedIn = false;
    }

    public function login(UserModel $user, bool $remember = false): ?string
    {
        $this->loginCalled = true;
        $this->loggedInUser = $user;
        $this->isLoggedIn = true;
        return $this->tokenToReturn;
    }
}

it('prevents double-hashing in UserModel::hashPassword for pre-hashed passwords', function () {
    $plain = 'my-secret-password-123';
    $firstHash = UserModel::hashPassword($plain);

    expect($firstHash)->not->toBe($plain)
        ->and(Hash::check($plain, $firstHash))->toBeTrue();

    // Passing the hash back into hashPassword should return the identical hash without re-hashing
    $secondCall = UserModel::hashPassword($firstHash);
    expect($secondCall)->toBe($firstHash)
        ->and(Hash::check($plain, $secondCall))->toBeTrue();
});

it('avoids double-hashing when a bcrypt hash is passed directly', function () {
    $knownBcrypt = password_hash('sample-pass', PASSWORD_BCRYPT);

    $result = UserModel::hashPassword($knownBcrypt);
    expect($result)->toBe($knownBcrypt);
});

it('blocks login when already logged in and force is false', function () {
    $provider = new TestEnhancementUserProvider();
    $guard = new TestEnhancementGuard($provider);
    $guard->isLoggedIn = true;

    $result = $guard->attemptResult([
        'username' => 'testuser',
        'password' => 'secret',
    ], force: false);

    expect($result->success)->toBeFalse()
        ->and($result->reason)->toBe('already_logged_in')
        ->and($guard->logoutCalled)->toBeFalse()
        ->and($guard->loginCalled)->toBeFalse();
});

it('allows re-authentication when already logged in and force is true', function () {
    $provider = new TestEnhancementUserProvider();
    $guard = new TestEnhancementGuard($provider);
    $guard->isLoggedIn = true;

    $user = new UserModel();
    $user->user_id = 42;
    $user->username = 'reauth_user';

    $provider->userToReturn = $user;
    $provider->validateResult = true;

    $result = $guard->attemptResult([
        'username' => 'reauth_user',
        'password' => 'valid_pass',
    ], remember: false, force: true);

    expect($result->success)->toBeTrue()
        ->and($result->token)->toBe('test-token-xyz')
        ->and($result->user->user_id)->toBe(42)
        ->and($guard->logoutCalled)->toBeTrue()
        ->and($guard->loginCalled)->toBeTrue()
        ->and($guard->loggedInUser->user_id)->toBe(42);
});

it('allows re-authentication when force is passed via credentials array', function () {
    $provider = new TestEnhancementUserProvider();
    $guard = new TestEnhancementGuard($provider);
    $guard->isLoggedIn = true;

    $user = new UserModel();
    $user->user_id = 99;

    $provider->userToReturn = $user;
    $provider->validateResult = true;

    $result = $guard->attemptResult([
        'username' => 'user_force',
        'password' => 'pass',
        'force' => true,
    ]);

    expect($result->success)->toBeTrue()
        ->and($result->token)->toBe('test-token-xyz')
        ->and($guard->logoutCalled)->toBeTrue()
        ->and($guard->loginCalled)->toBeTrue();
});

it('does not logout existing session if re-authentication credentials are invalid', function () {
    $provider = new TestEnhancementUserProvider();
    $guard = new TestEnhancementGuard($provider);
    $guard->isLoggedIn = true;

    $provider->userToReturn = null;

    $result = $guard->attemptResult([
        'username' => 'non_existing',
        'password' => 'bad_pass',
    ], force: true);

    expect($result->success)->toBeFalse()
        ->and($result->reason)->toBe('invalid_credentials')
        ->and($guard->logoutCalled)->toBeFalse()
        ->and($guard->loginCalled)->toBeFalse();
});

it('passes force flag from Manager::attempt and Manager::attemptResult', function () {
    $provider = new TestEnhancementUserProvider();
    $guard = new TestEnhancementGuard($provider);
    $guard->isLoggedIn = true;

    $user = new UserModel();
    $user->user_id = 77;
    $provider->userToReturn = $user;
    $provider->validateResult = true;

    $manager = new Manager($guard, $provider);
    $credentials = ['username' => 'alice', 'password' => 'secret'];

    // With force = false while logged in -> fails
    expect($manager->attempt($credentials, remember: false, force: false))->toBeFalse();
    expect($manager->attemptResult($credentials, remember: false, force: false)->reason)->toBe('already_logged_in');

    // With force = true while logged in -> succeeds
    $guard->isLoggedIn = true;
    expect($manager->attempt($credentials, remember: false, force: true))->toBeTrue();

    $guard->isLoggedIn = true;
    $res = $manager->attemptResult($credentials, remember: false, force: true);
    expect($res->success)->toBeTrue()
        ->and($res->user->user_id)->toBe(77);
});

it('normalizes Persian and Arabic digits and searches mobile in UserProvider', function () {
    $provider = new UserProvider();

    // Persian digits: ۰۹۱۲۳۴۵۶۷۸۹ -> 09123456789
    // In test DB, user with mobile 09123456789 exists
    $foundPersian = $provider->retrieveByLogin('۰۹۱۲۳۴۵۶۷۸۹');
    expect($foundPersian)->not->toBeNull()
        ->and($foundPersian->mobile)->toBe('09123456789');

    // Arabic digits: ٠٩١٢٣٤٥٦٧٨٩ -> 09123456789
    $foundArabic = $provider->retrieveByLogin('٠٩١٢٣٤٥٦٧٨٩');
    expect($foundArabic)->not->toBeNull()
        ->and($foundArabic->mobile)->toBe('09123456789');

    // Non-existent identifier returns null
    $notFound = $provider->retrieveByLogin('۰۹۹۹۹۹۹۹۹۹۹');
    expect($notFound)->toBeNull();
});

it('reads mobile field from credentials in UserProvider::retrieveByCredentials', function () {
    $provider = new TestEnhancementUserProvider();

    $provider->retrieveByCredentials([
        'mobile' => '۰۹۱۲۰۰۰۰۰۰۰',
    ]);

    expect($provider->lastLoginIdentifier)->toBe('۰۹۱۲۰۰۰۰۰۰۰');
});
