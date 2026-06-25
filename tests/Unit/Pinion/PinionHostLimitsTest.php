<?php

use Pinoox\Component\Pinion\PinionHostLimits;

it('tunes chunk size for constrained post_max_size', function () {
    $config = PinionHostLimits::tune([
        'chunk_size' => 5 * 1024 * 1024,
        'min_chunk_size' => 1024 * 1024,
        'max_chunk_size' => 10 * 1024 * 1024,
        'max_file_size' => 2 * 1024 * 1024 * 1024,
    ]);

    expect($config['chunk_size'])->toBeInt()
        ->and($config['chunk_size'])->toBeGreaterThan(0)
        ->and($config['max_chunk_size'])->toBeGreaterThanOrEqual($config['chunk_size'])
        ->and($config['host_limits'])->toBeArray()
        ->and($config['host_limits']['pinion_threshold'])->toBeInt();
});

it('exposes client pinion profile', function () {
    $profile = PinionHostLimits::clientProfile();

    expect($profile)->toHaveKeys([
        'upload_max_size',
        'post_max_size',
        'pinion_threshold',
        'chunk_size',
        'parallel',
        'max_file_size',
        'direct_upload_enabled',
    ]);
});
