<?php

use Pinoox\Component\Server\HostsFileMapper;

test('HostsFileMapper parses hosts entries for a hostname', function () {
    $temp = tempnam(sys_get_temp_dir(), 'pinoox-hosts-');
    file_put_contents($temp, implode(PHP_EOL, [
        '# Pinoox',
        '127.0.0.1 localhost',
        '192.168.1.5 mypinoox.com',
        '127.0.0.1 pinoox.test',
    ]) . PHP_EOL);

    expect(HostsFileMapper::entriesForHost('pinoox.test', $temp))->toBe([
        ['ip' => '127.0.0.1', 'host' => 'pinoox.test'],
    ])->and(HostsFileMapper::fileMapsToLoopback('pinoox.test', $temp))->toBeTrue()
        ->and(HostsFileMapper::conflictingIp('mypinoox.com', $temp))->toBe('192.168.1.5')
        ->and(HostsFileMapper::conflictingIp('pinoox.test', $temp))->toBeNull();

    @unlink($temp);
});

test('HostsFileMapper ensureLoopback skips write when fix disabled', function () {
    $temp = tempnam(sys_get_temp_dir(), 'pinoox-hosts-');
    file_put_contents($temp, "127.0.0.1 localhost\n");

    $result = HostsFileMapper::ensureLoopback('brand-new.test', false);

    expect($result['status'])->toBe(HostsFileMapper::STATUS_NEEDS_ADMIN)
        ->and(HostsFileMapper::fileMapsToLoopback('brand-new.test', $temp))->toBeFalse();

    @unlink($temp);
});

test('HostsFileMapper detects when hosts file needs elevation', function () {
    $temp = tempnam(sys_get_temp_dir(), 'pinoox-hosts-');
    file_put_contents($temp, "127.0.0.1 localhost\n");

    $madeReadOnly = false;

    if (PHP_OS_FAMILY !== 'Windows' && function_exists('chmod')) {
        $madeReadOnly = @chmod($temp, 0444);

        if ($madeReadOnly) {
            expect(HostsFileMapper::hostsFileNeedsElevation($temp))->toBeTrue();
        }
    }

    if ($madeReadOnly && function_exists('chmod')) {
        @chmod($temp, 0644);
    }

    expect(HostsFileMapper::hostsFileNeedsElevation($temp))->toBeFalse();

    @unlink($temp);
});

test('HostsFileMapper elevation hint matches platform family', function () {
    $hint = (new ReflectionClass(HostsFileMapper::class))
        ->getMethod('elevationPromptHint')
        ->invoke(null);

    expect($hint)->toBeString()->not->toBe('');
});
