<?php

use Pinoox\Component\Pinion\PinionConfig;
use Pinoox\Component\Pinion\ProtocolManager;
use Pinoox\Component\Pinion\PinooxPathResolver;
use Pinoox\Component\Pinion\StorageContext;
use Pinoox\Pinion\Config;
use Pinoox\Pinion\Store;
use Pinoox\Portal\Pinion;
use Tests\Support\TestSandbox;

it('resolves pinion staging paths', function () {
    $resolver = new PinooxPathResolver();
    $path = $resolver->resolve(StorageContext::STAGING_REFERENCE);

    expect($path)->toEndWith('assembled')
        ->and(is_dir(dirname($path)) || @mkdir(dirname($path), 0755, true))->toBeTrue();
});

it('detects storage mode from defaults', function () {
    expect(StorageContext::usesStorage([], ['mode' => 'local']))->toBeFalse()
        ->and(StorageContext::usesStorage([], ['mode' => 'storage']))->toBeTrue()
        ->and(StorageContext::usesStorage(['storage' => false], ['mode' => 'storage']))->toBeFalse();
});

it('creates portal http handler through protocol manager', function () {
    $handler = Pinion::http(['destination' => 'uploads/tmp', 'mode' => 'local']);

    expect($handler)->toBeInstanceOf(\Pinoox\Component\Pinion\HttpHandler::class);
});

it('completes a local pinion upload through protocol manager', function () {
    $dir = TestSandbox::ensure('pinion/' . uniqid('pincore-', true));

    $manager = new ProtocolManager(
        new Store($dir),
        PinionConfig::resolve(['storage_path' => $dir]),
        new PinooxPathResolver(),
    );

    $payload = 'pinion-pincore-bridge';
    $result = $manager->init(
        filename: 'bridge.txt',
        size: strlen($payload),
        destination: 'local:' . $dir . '/out',
        meta: ['mode' => 'local', 'storage' => false],
    );

    expect($result->success)->toBeTrue();

    $uploadId = $result->session->id;
    $receive = $manager->receive($uploadId, 0, $payload, hash('sha256', $payload));

    expect($receive->success)->toBeTrue();

    $complete = $manager->complete($uploadId);

    expect($complete->success)->toBeTrue()
        ->and($complete->path)->toEndWith('bridge.txt')
        ->and(file_get_contents((string) $complete->path))->toBe($payload);
});
