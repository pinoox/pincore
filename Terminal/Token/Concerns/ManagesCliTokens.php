<?php

namespace Pinoox\Terminal\Token\Concerns;

use Pinoox\Component\Token;
use Pinoox\Component\Transport\TransportRuntime;
use Pinoox\Model\TokenModel;
use Pinoox\Model\UserModel;
use Pinoox\Portal\Auth;
use Pinoox\Portal\Database\DB;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Pinoox\Terminal\User\Concerns\ManagesCliUsers;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

trait ManagesCliTokens
{
    use SelectsPackage;
    use ManagesCliUsers;

    protected function resolveTokenPackage(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $sectionTitle = 'Manage tokens for',
    ): string {
        return $this->resolvePackageRequired($input, $output, $io, [
            'sectionTitle' => $sectionTitle,
        ]);
    }

    protected function resolveTokenPackageInput(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $sectionTitle = 'Manage tokens for',
    ): string {
        $explicitPackage = (string) ($input->getArgument('package') ?? '');

        if ($explicitPackage !== '' && $this->looksLikePackageName($explicitPackage)) {
            return $explicitPackage;
        }

        return $this->resolveTokenPackage($input, $output, $io, $sectionTitle);
    }

    protected function prepareTokenScope(string $package): void
    {
        DB::ensureRegistered();
        TokenModel::clearBootedModels();
        UserModel::clearBootedModels();

        $this->prepareCliRequestContext();

        TransportRuntime::use($package);
        TokenModel::setPackage($package);
    }

    protected function prepareCliRequestContext(): void
    {
        $_SERVER['REMOTE_ADDR'] ??= '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] ??= 'pinoox-cli';
    }

    protected function resolveTokenIdentifier(InputInterface $input): string
    {
        return trim((string) ($input->getArgument('token') ?? ''));
    }

    protected function resolveToken(string $identifier): ?TokenModel
    {
        if ($identifier === '') {
            return null;
        }

        if (ctype_digit($identifier)) {
            return TokenModel::query()->where('token_id', (int) $identifier)->first();
        }

        return TokenModel::query()->where('token_key', $identifier)->first();
    }

    protected function resolveTokenInput(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $sectionTitle = 'Select token',
    ): ?TokenModel {
        $identifier = $this->resolveTokenIdentifier($input);

        if ($identifier !== '') {
            return $this->resolveToken($identifier);
        }

        if (!$input->isInteractive()) {
            throw new \RuntimeException('Token is required in non-interactive mode.');
        }

        return $this->promptTokenSelection($input, $output, $io, $sectionTitle);
    }

    protected function promptTokenSelection(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $sectionTitle = 'Select token',
    ): ?TokenModel {
        $tokens = TokenModel::query()->orderByDesc('token_id')->limit(50)->get();

        if ($tokens->isEmpty()) {
            return null;
        }

        $io->section($sectionTitle);

        $table = new Table($output);
        $table->setHeaders(['ID', 'Key', 'Name', 'User', 'Expires', 'Status']);
        foreach ($tokens as $token) {
            $table->addRow([
                $token->token_id,
                $this->maskTokenKey((string) $token->token_key),
                $token->token_name ?: '—',
                $token->user_id ?: '—',
                $this->formatExpiration($token),
                $this->tokenStatusLabel($token),
            ]);
        }
        $table->render();

        $candidates = [];
        foreach ($tokens as $token) {
            $candidates[] = (string) $token->token_id;
            $candidates[] = (string) $token->token_key;
        }

        $question = new Question('Token id or key: ');
        $question->setAutocompleterValues(array_values(array_unique($candidates)));
        $question->setValidator(function ($answer) {
            $answer = trim((string) $answer);
            if ($answer === '') {
                throw new \RuntimeException('Token is required.');
            }

            $token = $this->resolveToken($answer);
            if ($token === null) {
                throw new \RuntimeException('Token not found: ' . $answer);
            }

            return $token;
        });

        $selected = $this->getHelper('question')->ask($input, $output, $question);

        return $selected instanceof TokenModel ? $selected : null;
    }

    protected function maskTokenKey(string $key): string
    {
        if ($key === '') {
            return '—';
        }

        if (strlen($key) <= 12) {
            return str_repeat('*', strlen($key));
        }

        return substr($key, 0, 8) . '…' . substr($key, -4);
    }

    protected function isTokenExpired(TokenModel $token): bool
    {
        $expires = $token->expiration_date;

        if ($expires === null || $expires === '') {
            return false;
        }

        return strtotime((string) $expires) < time();
    }

    protected function tokenStatusLabel(TokenModel $token): string
    {
        return $this->isTokenExpired($token) ? 'expired' : 'active';
    }

    protected function formatExpiration(TokenModel $token): string
    {
        $expires = $token->expiration_date;

        if ($expires === null || $expires === '') {
            return '—';
        }

        return (string) $expires;
    }

    /**
     * @return array<string, mixed>
     */
    protected function tokenRow(TokenModel $token, bool $revealKey = false): array
    {
        return [
            'token_id' => $token->token_id,
            'token_key' => $revealKey ? $token->token_key : $this->maskTokenKey((string) $token->token_key),
            'token_name' => $token->token_name,
            'user_id' => $token->user_id,
            'app' => $token->app,
            'ip' => $token->ip,
            'user_agent' => $token->user_agent,
            'remote_url' => $token->remote_url,
            'expiration_date' => $this->formatExpiration($token),
            'status' => $this->tokenStatusLabel($token),
            'token_data' => $token->token_data,
            'created_at' => $token->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $token->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseTokenDataInput(?string $json, ?string $scalar = null): array
    {
        if (is_string($json) && trim($json) !== '') {
            $decoded = json_decode($json, true);

            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('Token data must be valid JSON object or array.');
            }

            return $decoded;
        }

        if (is_string($scalar) && $scalar !== '') {
            return ['value' => $scalar];
        }

        return [];
    }

    protected function applyTokenLifetime(InputInterface $input): void
    {
        $lifetime = $input->getOption('lifetime');
        $unit = strtolower(trim((string) ($input->getOption('unit') ?: 'day')));

        if ($lifetime === null || $lifetime === false || $lifetime === '') {
            return;
        }

        if (!is_numeric($lifetime) || (int) $lifetime <= 0) {
            throw new \InvalidArgumentException('Lifetime must be a positive number.');
        }

        if (!in_array($unit, ['min', 'hour', 'day'], true)) {
            throw new \InvalidArgumentException('Unit must be min, hour, or day.');
        }

        Token::lifeTime((int) $lifetime, $unit);
    }

    protected function resolveTokenUserId(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        ?string $argument = 'user',
    ): int {
        $userOption = $input->getOption('user');
        $userArg = $argument !== null && $input->hasArgument($argument)
            ? trim((string) $input->getArgument($argument))
            : '';
        $identifier = is_string($userOption) && $userOption !== '' ? $userOption : $userArg;

        if ($identifier !== '') {
            $user = $this->resolveUser($identifier);

            if ($user === null) {
                throw new \InvalidArgumentException('User not found: ' . $identifier);
            }

            return (int) $user->user_id;
        }

        if (!$input->isInteractive()) {
            throw new \RuntimeException('User is required (--user) in non-interactive mode.');
        }

        $user = $this->promptUserSelection($input, $output, $io, 'Select user for token');

        if ($user === null) {
            throw new \RuntimeException('User is required.');
        }

        return (int) $user->user_id;
    }

    protected function createToken(
        array $data,
        int $userId,
        ?string $name = null,
        ?string $tokenKey = null,
    ): TokenModel {
        $this->applyTokenLifetimeFromDefaults();

        return TokenModel::create([
            'token_key' => $tokenKey ?: $this->generateTokenKey(),
            'token_name' => $name,
            'token_data' => $data,
            'user_id' => $userId,
            'ip' => '127.0.0.1',
            'user_agent' => 'pinoox-cli',
            'expiration_date' => $this->calculateTokenExpiration(),
        ]);
    }

    protected function generateTokenKey(): string
    {
        $time = str_replace(['.', ' '], '', microtime());
        $str = str_shuffle('abcefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890');
        $text = substr($str, 0, random_int(10, 40));

        return md5($time . $text . '127.0.0.1');
    }

    protected function calculateTokenExpiration(): string
    {
        return date('Y-m-d H:i:s', time() + Token::$lifeTime);
    }

    protected function applyTokenLifetimeFromDefaults(): void
    {
        if (Token::$lifeTime <= 0) {
            Token::lifeTime(30, 'day');
        }
    }

    protected function revokeUserTokens(int $userId): int
    {
        return Auth::revokeSessions($userId);
    }
}

