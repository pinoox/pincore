<?php

/**
 * Minimal runtime checks for standalone pincore boot (no Composer autoload yet).
 */

require_once __DIR__ . '/core-path.php';

function pinoox_base_path(): string
{
    return pinoox_normalize_path(PINOOX_BASE_PATH);
}

function pinoox_core_path(): string
{
    return rtrim(pinoox_normalize_path(PINOOX_CORE_PATH), '/');
}

function pinoox_composer_json_files(): array
{
    $base = pinoox_base_path();
    $core = pinoox_core_path();

    return array_values(array_unique([
        $base . '/composer.json',
        $core . '/composer.json',
    ]));
}

function pinoox_normalize_php_constraint(string $constraint): string
{
    $constraint = trim($constraint);

    if ($constraint === '') {
        return '8.1.0';
    }

    if (preg_match('/(\d+\.\d+(?:\.\d+)?)/', $constraint, $matches) !== 1) {
        return '8.1.0';
    }

    $version = $matches[1];

    if (substr_count($version, '.') === 1) {
        $version .= '.0';
    }

    return $version;
}

function pinoox_min_php_version(): string
{
    $require = [];

    foreach (pinoox_composer_json_files() as $file) {
        if (!is_file($file)) {
            continue;
        }

        $json = json_decode((string) file_get_contents($file), true);

        if (!is_array($json)) {
            continue;
        }

        $require = array_merge($require, is_array($json['require'] ?? null) ? $json['require'] : []);
    }

    return pinoox_normalize_php_constraint((string) ($require['php'] ?? '8.1.0'));
}

function pinoox_php_version_ok(?string $minimum = null): bool
{
    $minimum = $minimum ?? pinoox_min_php_version();

    return version_compare(PHP_VERSION, $minimum, '>=');
}

function pinoox_vendor_autoload_path(): string
{
    return pinoox_base_path() . '/vendor/autoload.php';
}

function pinoox_vendor_installed(): bool
{
    return is_file(pinoox_vendor_autoload_path());
}

function pinoox_check_runtime_requirements(): void
{
    if (!pinoox_php_version_ok()) {
        $message = sprintf(
            'PHP %s or higher is required (composer.json). Current version: %s%s',
            pinoox_min_php_version(),
            PHP_VERSION,
            PHP_EOL,
        );

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message);
        } else {
            header('Content-Type: text/plain; charset=UTF-8');
            http_response_code(503);
            echo $message;
        }

        exit(1);
    }

    if (!pinoox_vendor_installed()) {
        $message = 'Composer dependencies are missing. Run composer install in '
            . pinoox_base_path()
            . PHP_EOL;

        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message);
        } else {
            header('Content-Type: text/plain; charset=UTF-8');
            http_response_code(500);
            echo $message;
        }

        exit(1);
    }
}
