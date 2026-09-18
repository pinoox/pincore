<?php

use Pinoox\Component\Server\ServeLocalDomain;
use Pinoox\Component\Template\Frontend\FrontendDevSession;

test('ServeLocalDomain normalizes hostnames and builds http URLs', function () {
    expect(ServeLocalDomain::normalize('pinoox.test'))->toBe('pinoox.test')
        ->and(ServeLocalDomain::normalize('http://Pinoox.Test:8000'))->toBe('pinoox.test')
        ->and(ServeLocalDomain::normalize(''))->toBeNull()
        ->and(ServeLocalDomain::normalize('not a domain!'))->toBeNull()
        ->and(ServeLocalDomain::httpUrl('pinoox.test', 8000))->toBe('http://pinoox.test:8000')
        ->and(ServeLocalDomain::httpUrl('pinoox.test', 8000, true))->toBe('http://pinoox.test')
        ->and(ServeLocalDomain::browserHttpUrl('pinoox.test', '127.0.0.1', 8002))->toBe('http://pinoox.test:8002')
        ->and(ServeLocalDomain::proxyHttpUrl('pinoox.test', '127.0.0.1', 8002))->toBe('http://127.0.0.1:8002')
        ->and(ServeLocalDomain::httpUrl('pinoox.test', 80))->toBe('http://pinoox.test')
        ->and(ServeLocalDomain::hostsFileEntry('pinoox.test'))->toBe('127.0.0.1 pinoox.test');
});

test('ServeLocalDomain validates hostnames', function () {
    expect(ServeLocalDomain::isValidHostname('pinoox.test'))->toBeTrue()
        ->and(ServeLocalDomain::isValidHostname('localhost'))->toBeTrue()
        ->and(ServeLocalDomain::isValidHostname('-bad.test'))->toBeFalse();
});

test('FrontendDevSession uses SERVER_DOMAIN for browser URLs', function () {
    putenv('SERVER_DOMAIN=pinoox.test');
    $_ENV['SERVER_DOMAIN'] = 'pinoox.test';
    $_SERVER['SERVER_DOMAIN'] = 'pinoox.test';

    $port = \Pinoox\Component\Server\ServerPort::resolve(null, '127.0.0.1', 28000);
    $session = FrontendDevSession::fromOptions(
        'com_demo_app',
        ['stack' => 'vue'],
        '127.0.0.1',
        $port,
        'com_demo_app',
        true,
        null,
        null,
        null,
        '',
        'pinoox.test',
    );

    putenv('SERVER_DOMAIN');
    unset($_ENV['SERVER_DOMAIN'], $_SERVER['SERVER_DOMAIN']);

    expect($session->serveDomain)->toBe('pinoox.test')
        ->and($session->phpAppUrl)->toBe('http://127.0.0.1:' . $port)
        ->and($session->phpOrigin())->toBe('http://pinoox.test:' . $port);
});
