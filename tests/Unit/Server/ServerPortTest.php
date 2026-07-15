<?php

use Pinoox\Component\Server\ServerPort;

test('ServerPort keeps default 8000 regardless of local domain', function () {
    $previousEnv = $_ENV['SERVER_PORT'] ?? null;
    $previousServer = $_SERVER['SERVER_PORT'] ?? null;
    $previousGetenv = getenv('SERVER_PORT');

    unset($_ENV['SERVER_PORT'], $_SERVER['SERVER_PORT']);
    putenv('SERVER_PORT');

    expect(ServerPort::preferredServePort())->toBe(8000);

    if ($previousEnv !== null) {
        $_ENV['SERVER_PORT'] = $previousEnv;
    }

    if ($previousServer !== null) {
        $_SERVER['SERVER_PORT'] = $previousServer;
    }

    if ($previousGetenv !== false) {
        putenv('SERVER_PORT=' . $previousGetenv);
    } else {
        putenv('SERVER_PORT');
    }
});

test('ServerPort parses Windows excluded port range output', function () {
    $output = <<<'TXT'
Protocol tcp Port Exclusion Ranges

Start Port    End Port
----------    --------
      7935        8034
      8124        8223
TXT;

    expect(ServerPort::parseExcludedPortRangeOutput($output))->toBe([
        ['start' => 7935, 'end' => 8034],
        ['start' => 8124, 'end' => 8223],
    ]);
});

test('ServerPort candidatePorts skips Windows reserved ranges and adds fallback anchors', function () {
    $windowsRanges = [
        ['start' => 7935, 'end' => 8034],
    ];

    $candidates = ServerPort::candidatePorts(8000, 50, $windowsRanges);

    expect($candidates)->not->toContain(8000)
        ->and($candidates)->not->toContain(8009)
        ->and($candidates)->toContain(8035)
        ->and($candidates)->toContain(8080)
        ->and($candidates)->toContain(10000)
        ->and(count($candidates))->toBe(50);
});

test('ServerPort candidatePorts keeps linear scan when start port is not reserved', function () {
    $candidates = ServerPort::candidatePorts(9000, 5, []);

    expect($candidates)->toBe([9000, 9001, 9002, 9003, 9004]);
});

test('ServerPort isWindowsExcluded respects injected ranges on Windows', function () {
    $ranges = [
        ['start' => 7935, 'end' => 8034],
    ];

    expect(ServerPort::isWindowsExcluded(8000, $ranges))->toBeTrue()
        ->and(ServerPort::isWindowsExcluded(8035, $ranges))->toBeFalse();
})->skip(PHP_OS_FAMILY !== 'Windows', 'Windows-only exclusion detection');
