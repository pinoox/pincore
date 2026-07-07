<?php

use Pinoox\Component\Template\Frontend\FrontendDevSession;

it('shows PHP origin for single-app dev and app-router URLs for platform dev', function () {
    $session = new FrontendDevSession(
        'com_pinoox_manager',
        '127.0.0.1',
        8000,
        true,
        'com_pinoox_manager',
        5173,
        'http://127.0.0.1:8000/manager',
        ['/manager'],
        '127.0.0.1',
        true,
        false,
    );

    expect($session->displayAppUrls())->toBe(['http://127.0.0.1:8000']);

    $platformSession = new FrontendDevSession(
        'com_pinoox_manager',
        '127.0.0.1',
        8000,
        false,
        null,
        5173,
        'http://127.0.0.1:8000/manager',
        ['/manager'],
        '127.0.0.1',
        true,
        true,
    );

    $urls = $platformSession->displayAppUrls();

    expect($urls)->not->toBe(['http://127.0.0.1:8000']);
});
