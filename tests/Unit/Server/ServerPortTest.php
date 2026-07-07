<?php

use Pinoox\Component\Server\ServerPort;

it('auto-selects a free port when none is specified', function () {
    $port = ServerPort::resolve(null, '127.0.0.1', 38470, 1);

    expect($port)->toBe(38470);
});

it('throws when an explicit port is already in use', function () {
    $host = '127.0.0.1';
    $port = ServerPort::resolve(null, $host, 38471, 1);
    $socket = stream_socket_server(sprintf('tcp://%s:%d', $host, $port));

    try {
        expect(fn () => ServerPort::resolve($port, $host))
            ->toThrow(\RuntimeException::class, 'already in use');
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
});

it('selects the next free port when the preferred port is busy', function () {
    $host = '127.0.0.1';
    $preferred = 38472;
    $socket = stream_socket_server(sprintf('tcp://%s:%d', $host, $preferred));

    try {
        expect(ServerPort::resolve(null, $host, $preferred, 5))->toBe($preferred + 1);
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
});

it('reports port availability', function () {
    $host = '127.0.0.1';
    $port = 38473;
    $socket = stream_socket_server(sprintf('tcp://%s:%d', $host, $port));

    try {
        expect(ServerPort::isAvailable($host, $port))->toBeFalse()
            ->and(ServerPort::isAvailable($host, $port + 1))->toBeTrue();
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
});
