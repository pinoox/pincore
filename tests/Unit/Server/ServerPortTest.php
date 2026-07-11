<?php

use Pinoox\Component\Server\ServerPort;

test('ServerPort prefers port 80 when a local domain is set', function () {
    $previousEnv = $_ENV['SERVER_PORT'] ?? null;
    $previousServer = $_SERVER['SERVER_PORT'] ?? null;
    $previousGetenv = getenv('SERVER_PORT');

    unset($_ENV['SERVER_PORT'], $_SERVER['SERVER_PORT']);
    putenv('SERVER_PORT');

    expect(ServerPort::preferredServePort(true))->toBe(80)
        ->and(ServerPort::preferredServePort(false))->toBe(8000);

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

test('ServerPort keeps SERVER_PORT when explicitly configured with a domain', function () {
    $_ENV['SERVER_PORT'] = '9000';
    $_SERVER['SERVER_PORT'] = '9000';
    putenv('SERVER_PORT=9000');

    expect(ServerPort::preferredServePort(true))->toBe(9000);

    unset($_ENV['SERVER_PORT'], $_SERVER['SERVER_PORT']);
    putenv('SERVER_PORT');
});
