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
