<?php

use Pinoox\Component\Template\Frontend\FrontendDevPresenter;
use Pinoox\Component\Template\Frontend\FrontendDevSession;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

function frontendDevPresenterIo(): array
{
    $output = new BufferedOutput();
    $io = new SymfonyStyle(new ArrayInput([]), $output);

    return [$io, $output];
}

function frontendDevPresenterSession(string $package, int $vitePort = 5173): FrontendDevSession
{
    return new FrontendDevSession(
        package: $package,
        serveHost: '127.0.0.1',
        servePort: 8000,
        serveDomain: null,
        serveAppLocked: false,
        serveAppBinding: null,
        vitePort: $vitePort,
        phpAppUrl: 'http://127.0.0.1:8000/',
        proxyPrefixes: [],
    );
}

it('hides context and vite stacks table for a single dev stack', function (): void {
    [$io, $output] = frontendDevPresenterIo();

    FrontendDevPresenter::render(
        $io,
        [['context' => 'site', 'theme' => 'landing']],
        [frontendDevPresenterSession('com_test_app')],
        'com_test_app',
    );

    $text = $output->fetch();

    expect($text)
        ->toContain('Vite port')
        ->toContain('5173')
        ->not->toContain('Contexts')
        ->not->toContain('Vite stacks')
        ->not->toContain('Context')
        ->toContain('Open  http://127.0.0.1:8000');
});

it('shows vite stacks and contexts when multiple stacks run', function (): void {
    [$io, $output] = frontendDevPresenterIo();

    FrontendDevPresenter::render(
        $io,
        [
            ['context' => 'site', 'theme' => 'landing'],
            ['context' => 'panel', 'theme' => 'panel'],
        ],
        [
            frontendDevPresenterSession('com_test_app', 5173),
            frontendDevPresenterSession('com_test_app', 5174),
        ],
        'com_test_app',
    );

    $text = $output->fetch();

    expect($text)
        ->toContain('Contexts')
        ->toContain('site, panel')
        ->toContain('Vite stacks')
        ->toContain('2 dev servers')
        ->toContain('5174');
});

it('lists one open url per app on platform multi-app dev', function (): void {
    [$io, $output] = frontendDevPresenterIo();

    $manager = frontendDevPresenterSession('com_pinoox_manager');
    $welcome = frontendDevPresenterSession('com_pinoox_welcome', 5174);

    FrontendDevPresenter::render(
        $io,
        [
            ['context' => null, 'theme' => 'panel'],
            ['context' => null, 'theme' => 'welcome'],
        ],
        [$manager, $welcome],
        FrontendDevSession::SERVE_PLATFORM,
    );

    $text = $output->fetch();

    expect($text)
        ->toContain('Open in browser')
        ->toContain('com_pinoox_manager')
        ->toContain('com_pinoox_welcome')
        ->not->toContain('PHP URL');
});
