<?php

use Pinoox\Component\Template\Seo\SeoMeta;
use Pinoox\Portal\View;

beforeEach(function () {
    Pinoox\Component\Test\AppTestKit::boot();
});

it('shares seo meta through view portal', function () {
    View::shareSeo([
        'title' => 'Products',
        'description' => 'Catalog page',
    ]);

    $shared = View::get('_seo');

    expect($shared)->toBeInstanceOf(SeoMeta::class)
        ->and($shared->title)->toBe('Products');
});

it('renders seo_tags helper from shared meta', function () {
    share_seo([
        'title' => 'About',
        'canonical' => 'https://example.com/about',
    ]);

    $html = seo_tags();

    expect($html)
        ->toContain('<title>About</title>')
        ->toContain('rel="canonical"');
});

it('renders vite_tags helper without throwing', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-vite-tags-' . uniqid();
    mkdir($themePath . '/.pinoox', 0777, true);
    mkdir($themePath . '/dist/.vite', 0777, true);
    file_put_contents($themePath . '/dist/.vite/manifest.json', json_encode([
        'src/main.js' => ['file' => 'assets/main.js', 'isEntry' => true],
    ]));

    file_put_contents($themePath . '/.pinoox/dev.json', json_encode(['viteUrl' => 'http://127.0.0.1:5173'], JSON_PRETTY_PRINT));

    putenv('PINOOX_VITE_HMR=1');
    $_ENV['PINOOX_VITE_HMR'] = '1';
    $_SERVER['PINOOX_VITE_HMR'] = '1';

    $helper = new Pinoox\Component\Helpers\ViteHelper($themePath);
    $tags = implode('', $helper->vite('src/main.js'));

    expect($tags)->toContain('@vite/client');

    putenv('PINOOX_VITE_HMR');
    unset($_ENV['PINOOX_VITE_HMR'], $_SERVER['PINOOX_VITE_HMR']);

    @unlink($themePath . '/.pinoox/dev.json');
    @rmdir($themePath . '/.pinoox');
    @unlink($themePath . '/dist/.vite/manifest.json');
    @rmdir($themePath . '/dist/.vite');
    @rmdir($themePath . '/dist');
    @rmdir($themePath);
});

it('splits vite css and js tags for head and body placement', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-vite-split-' . uniqid();
    mkdir($themePath . '/dist/.vite', 0777, true);
    file_put_contents($themePath . '/dist/.vite/manifest.json', json_encode([
        'src/main.js' => [
            'file' => 'assets/main-abc123.js',
            'css' => ['assets/main-def456.css'],
            'isEntry' => true,
        ],
    ]));

    $helper = new Pinoox\Component\Helpers\ViteHelper($themePath);

    expect($helper->cssTags('src/main.js'))
        ->toContain('<link rel="stylesheet"')
        ->toContain('main-def456.css')
        ->and($helper->jsTags('src/main.js'))
        ->toContain('<script type="module"')
        ->toContain('main-abc123.js')
        ->and($helper->tags('src/main.js'))
        ->toContain('<link rel="stylesheet"')
        ->toContain('<script type="module"');

    @unlink($themePath . '/dist/.vite/manifest.json');
    @rmdir($themePath . '/dist/.vite');
    @rmdir($themePath . '/dist');
    @rmdir($themePath);
});

it('returns dev js tags only from vite_js_tags and empty css in dev mode', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-vite-dev-split-' . uniqid();
    mkdir($themePath . '/.pinoox', 0777, true);
    file_put_contents($themePath . '/.pinoox/dev.json', json_encode(['viteUrl' => 'http://127.0.0.1:5173'], JSON_PRETTY_PRINT));

    putenv('PINOOX_VITE_HMR=1');
    $_ENV['PINOOX_VITE_HMR'] = '1';
    $_SERVER['PINOOX_VITE_HMR'] = '1';

    $helper = new Pinoox\Component\Helpers\ViteHelper($themePath);

    expect($helper->cssTags('src/main.js'))->toBe('')
        ->and($helper->jsTags('src/main.js'))->toContain('@vite/client');

    putenv('PINOOX_VITE_HMR');
    unset($_ENV['PINOOX_VITE_HMR'], $_SERVER['PINOOX_VITE_HMR']);

    @unlink($themePath . '/.pinoox/dev.json');
    @rmdir($themePath . '/.pinoox');
    @rmdir($themePath);
});

