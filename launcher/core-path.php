<?php

function pinoox_normalize_path(string $path): string
{
    return rtrim(str_replace('\\', '/', $path), '/');
}

function pinoox_core_package_root(): string
{
    return pinoox_normalize_path(dirname(__DIR__));
}

function pinoox_resolve_host_base_path(string $corePath): string
{
    $configured = getenv('PINOOX_BASE_PATH') ?: null;

    if (is_string($configured) && $configured !== '') {
        $configured = pinoox_normalize_path($configured);

        if (!preg_match('/^[A-Za-z]:\//', $configured) && !str_starts_with($configured, '/')) {
            $configured = pinoox_normalize_path(pinoox_core_package_root() . '/' . $configured);
        }

        return $configured;
    }

    $corePath = pinoox_normalize_path($corePath);

    if (preg_match('#/vendor/pinoox/pincore$#', $corePath) === 1) {
        $host = dirname($corePath, 3);

        if (is_file($host . '/composer.json')) {
            return $host;
        }
    }

    $parent = dirname($corePath);

    if ($parent !== $corePath
        && basename($corePath) === 'pincore'
        && is_file($parent . '/launcher/bootstrap.php')
        && is_file($parent . '/composer.json')) {
        return $parent;
    }

    return $corePath;
}

$coreRoot = pinoox_core_package_root();

defined('PINOOX_CORE_PATH') || define('PINOOX_CORE_PATH', $coreRoot . '/');
defined('PINOOX_BASE_PATH') || define('PINOOX_BASE_PATH', pinoox_resolve_host_base_path($coreRoot));
