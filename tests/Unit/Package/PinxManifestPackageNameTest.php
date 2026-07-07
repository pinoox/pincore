<?php

use Pinoox\Component\Kernel\Exception;
use Pinoox\Component\Package\PackageName;
use Pinoox\Component\Package\Pinx\PinxManifest;

it('validates app package names in pinx manifest', function () {
    $manifest = PinxManifest::fromArray([
        'format' => PinxManifest::FORMAT,
        'format_version' => PinxManifest::FORMAT_VERSION,
        'type' => PinxManifest::TYPE_APP,
        'package' => 'io_yoosefap_ai',
        'name' => 'AI',
        'version_name' => '1.0',
        'version_code' => 1,
    ]);

    expect(fn () => $manifest->validate())->not->toThrow(Exception::class);
});

it('rejects invalid app package names in pinx manifest', function () {
    $manifest = PinxManifest::fromArray([
        'format' => PinxManifest::FORMAT,
        'format_version' => PinxManifest::FORMAT_VERSION,
        'type' => PinxManifest::TYPE_APP,
        'package' => 'invalid-app',
        'name' => 'Bad',
        'version_name' => '1.0',
        'version_code' => 1,
    ]);

    expect(fn () => $manifest->validate())
        ->toThrow(Exception::class, 'Invalid package in manifest');
});

it('validates target_app package names for theme manifests', function () {
    $manifest = PinxManifest::fromArray([
        'format' => PinxManifest::FORMAT,
        'format_version' => PinxManifest::FORMAT_VERSION,
        'type' => PinxManifest::TYPE_THEME,
        'package' => 'spark',
        'target_app' => 'com_pinoox_manager',
        'theme_name' => 'spark',
        'name' => 'Spark',
        'version_name' => '1.0',
        'version_code' => 1,
    ]);

    expect(fn () => $manifest->validate())->not->toThrow(Exception::class);
});

it('rejects invalid target_app in theme manifests', function () {
    $manifest = PinxManifest::fromArray([
        'format' => PinxManifest::FORMAT,
        'format_version' => PinxManifest::FORMAT_VERSION,
        'type' => PinxManifest::TYPE_THEME,
        'package' => 'spark',
        'target_app' => 'manager',
        'theme_name' => 'spark',
        'name' => 'Spark',
        'version_name' => '1.0',
        'version_code' => 1,
    ]);

    expect(fn () => $manifest->validate())
        ->toThrow(Exception::class, 'Invalid target_app in manifest');
});

it('accepts uppercase package names as equivalent canonical values', function () {
    $manifest = PinxManifest::fromArray([
        'format' => PinxManifest::FORMAT,
        'format_version' => PinxManifest::FORMAT_VERSION,
        'type' => PinxManifest::TYPE_APP,
        'package' => 'IO_YOOSEFAP_AI',
        'name' => 'AI',
        'version_name' => '1.0',
        'version_code' => 1,
    ]);

    expect(fn () => $manifest->validate())->not->toThrow(Exception::class)
        ->and(PackageName::canonical($manifest->package()))->toBe('io_yoosefap_ai');
});
