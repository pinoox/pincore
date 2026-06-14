<?php

namespace Pinoox\Terminal\Database\Concerns;

use Pinoox\Component\Database\DatabaseConfig;
use Pinoox\Component\Database\DatabaseConnectionNormalizer;
use Pinoox\Component\Database\DatabaseConnectionToolkit;
use Pinoox\Component\Database\PlatformDatabaseStore;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\Database\DB;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

trait ManagesCliDatabase
{
    use SelectsPackage;

    protected function resolveDatabaseTarget(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $sectionTitle = 'Database target',
    ): string {
        return $this->resolvePackageRequired($input, $output, $io, [
            'sectionTitle' => $sectionTitle,
        ]);
    }

    protected function prepareDatabaseCli(): void
    {
        DB::ensureRegistered();
    }

    /**
     * @return list<string>
     */
    protected function platformConnectionNames(): array
    {
        $root = PlatformDatabaseStore::platformRoot();
        $connections = is_array($root['connections'] ?? null) ? $root['connections'] : [];

        return array_values(array_filter(array_keys($connections), 'is_string'));
    }

    protected function resolvePlatformConnectionName(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        ?string $argument = 'connection',
    ): string {
        $name = trim((string) ($input->hasArgument($argument) ? $input->getArgument($argument) : ''));

        if ($name !== '') {
            if (!in_array($name, $this->platformConnectionNames(), true)) {
                throw new \InvalidArgumentException('Platform connection not found: ' . $name);
            }

            return $name;
        }

        $names = $this->platformConnectionNames();

        if ($names === []) {
            throw new \RuntimeException('No platform connections are configured.');
        }

        if (count($names) === 1) {
            return $names[0];
        }

        if (!$input->isInteractive()) {
            throw new \RuntimeException('Connection name is required in non-interactive mode.');
        }

        $question = new Question('Platform connection: ');
        $question->setAutocompleterValues($names);
        $question->setValidator(function ($answer) use ($names) {
            $answer = strtolower(trim((string) $answer));

            if (!in_array($answer, $names, true)) {
                throw new \RuntimeException('Connection not found: ' . $answer);
            }

            return $answer;
        });

        return $this->getHelper('question')->ask($input, $output, $question);
    }

    /**
     * @return array<string, mixed>
     */
    protected function readConnectionInput(InputInterface $input, SymfonyStyle $io, bool $requireCredentials = false): array
    {
        $data = [];

        foreach ([
            'driver' => 'driver',
            'host' => 'host',
            'database' => 'database',
            'username' => 'username',
            'password' => 'password',
            'prefix' => 'prefix',
            'port' => 'port',
            'timezone' => 'timezone',
            'use' => 'use',
        ] as $option => $key) {
            if (!$input->hasOption($option)) {
                continue;
            }

            $value = $input->getOption($option);

            if ($value !== null && $value !== false && $value !== '') {
                $data[$key] = $value;
            }
        }

        if ($requireCredentials) {
            $driver = DatabaseConnectionNormalizer::driverName($data);
            $data['driver'] = $driver;

            if (!isset($data['host']) && $input->isInteractive()) {
                $data['host'] = (string) $io->ask('Host', '127.0.0.1');
            }

            if (!isset($data['database']) && $input->isInteractive()) {
                $data['database'] = (string) $io->ask('Database name');
            }

            if (!isset($data['username']) && $input->isInteractive()) {
                $data['username'] = (string) $io->ask('Username', 'root');
            }

            if (!array_key_exists('password', $data) && $input->isInteractive()) {
                $data['password'] = (string) $io->askHidden('Password');
            }
        }

        /** @var list<string> $setValues */
        $setValues = $input->hasOption('set') ? $input->getOption('set') : [];

        foreach ($setValues as $pair) {
            [$key, $value] = $this->parseSetPair((string) $pair);
            $data[$key] = $value;
        }

        return $data;
    }

    /**
     * @return array{0: string, 1: mixed}
     */
    protected function parseSetPair(string $pair): array
    {
        if (!str_contains($pair, '=')) {
            throw new \InvalidArgumentException('Invalid --set value (expected key=value): ' . $pair);
        }

        [$key, $value] = explode('=', $pair, 2);
        $key = trim($key);
        $aliases = [
            'db' => 'database',
            'user' => 'username',
            'pass' => 'password',
            'connection' => 'use',
        ];
        $key = $aliases[$key] ?? $key;

        if ($key === '') {
            throw new \InvalidArgumentException('Invalid --set key: ' . $pair);
        }

        return [$key, $value];
    }

    protected function isPlatformTarget(string $target): bool
    {
        return $target === 'platform';
    }

    protected function isAppTarget(string $target): bool
    {
        return !$this->isPlatformTarget($target) && AppEngine::exists($target);
    }

    protected function validateConnectionName(string $name): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException('Connection name must start with a letter and contain only letters, numbers, and underscores.');
        }
    }

    protected function envOverrideNote(SymfonyStyle $io): void
    {
        $io->note([
            'Runtime may override Pinker values when .env defines DB_* keys (env-over-pinker).',
            'CLI writes to Pinker; check .env if changes do not appear at runtime.',
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function renderConnectionDetails(SymfonyStyle $io, array $row): void
    {
        $definitions = [];

        foreach ($row as $key => $value) {
            if ($key === 'raw' || is_array($value)) {
                continue;
            }

            $definitions[] = [ucfirst(str_replace('_', ' ', (string) $key)) => is_scalar($value) ? (string) $value : '—'];
        }

        if ($definitions !== []) {
            $io->definitionList(...$definitions);
        }
    }

    protected function defaultPlatformDriver(): string
    {
        $root = PlatformDatabaseStore::platformRoot();

        return (string) ($root['default'] ?? DatabaseConfig::DEFAULT_CONNECTION);
    }
}
