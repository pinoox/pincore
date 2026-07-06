<?php

use Pinoox\Component\Template\Frontend\FrontendConfig;
use Pinoox\Component\Template\Seo\SeoMeta;
use Pinoox\Component\Helpers\ViteHelper;

test('FrontendConfig detects vue stack from package.json', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-theme-vue-' . uniqid();
    mkdir($themePath, 0777, true);
    file_put_contents($themePath . '/package.json', json_encode([
        'dependencies' => ['vue' => '^3.5.0'],
    ]));

    $config = FrontendConfig::forThemePath($themePath);

    expect($config['stack'])->toBe('vue')
        ->and($config['entry'])->toBe('src/main.js');

    @unlink($themePath . '/package.json');
    @rmdir($themePath);
});

test('FrontendConfig detects react stack from package.json', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-theme-react-' . uniqid();
    mkdir($themePath, 0777, true);
    file_put_contents($themePath . '/package.json', json_encode([
        'dependencies' => ['react' => '^19.0.0', 'react-dom' => '^19.0.0'],
    ]));

    $config = FrontendConfig::forThemePath($themePath);

    expect($config['stack'])->toBe('react')
        ->and($config['entry'])->toBe('src/main.jsx');

    @unlink($themePath . '/package.json');
    @rmdir($themePath);
});

test('SeoMeta renders title, description, canonical and json-ld', function () {
    $meta = SeoMeta::fromArray([
        'title' => 'Products',
        'description' => 'Browse products',
        'canonical' => 'https://example.com/products',
        'json_ld' => [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => 'Products',
        ],
    ]);

    $html = $meta->renderTags();

    expect($html)
        ->toContain('<title>Products</title>')
        ->toContain('name="description"')
        ->toContain('rel="canonical"')
        ->toContain('application/ld+json');
});

test('ViteHelper uses dev server tags when hot file exists', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-vite-hot-' . uniqid();
    mkdir($themePath . '/dist', 0777, true);
    file_put_contents($themePath . '/dist/hot', 'http://127.0.0.1:5173');

    $helper = new ViteHelper($themePath);
    $tags = $helper->vite('src/main.js');

    expect($tags)->toHaveCount(2)
        ->and($tags[0])->toContain('@vite/client')
        ->and($tags[1])->toContain('src/main.js');

    @unlink($themePath . '/dist/hot');
    @rmdir($themePath . '/dist');
    @rmdir($themePath);
});

test('ViteHelper reads production manifest chunks', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-vite-manifest-' . uniqid();
    mkdir($themePath . '/dist/.vite', 0777, true);

    file_put_contents($themePath . '/dist/.vite/manifest.json', json_encode([
        'src/main.js' => [
            'file' => 'assets/main-abc123.js',
            'css' => ['assets/main-abc123.css'],
            'isEntry' => true,
        ],
    ]));

    $helper = new ViteHelper($themePath);
    $tags = $helper->vite('src/main.js');

    expect(implode("\n", $tags))
        ->toContain('.js')
        ->toContain('.css')
        ->toContain('modulepreload');

    @unlink($themePath . '/dist/.vite/manifest.json');
    @rmdir($themePath . '/dist/.vite');
    @rmdir($themePath . '/dist');
    @rmdir($themePath);
});

test('ViteHelper supports multiple entry points', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-vite-multi-' . uniqid();
    mkdir($themePath . '/dist', 0777, true);
    file_put_contents($themePath . '/dist/hot', 'http://127.0.0.1:5173');

    $helper = new ViteHelper($themePath);
    $tags = $helper->vite(['src/main.js', 'src/admin.js']);

    expect($tags)->toHaveCount(3)
        ->and($tags[0])->toContain('@vite/client')
        ->and($tags[1])->toContain('src/main.js')
        ->and($tags[2])->toContain('src/admin.js');

    @unlink($themePath . '/dist/hot');
    @rmdir($themePath . '/dist');
    @rmdir($themePath);
});

test('ViteHelper resolves versioned asset URLs from manifest', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-vite-asset-' . uniqid();
    mkdir($themePath . '/dist/.vite', 0777, true);

    file_put_contents($themePath . '/dist/.vite/manifest.json', json_encode([
        'src/images/logo.png' => [
            'file' => 'assets/logo-xyz.png',
        ],
    ]));

    $helper = new ViteHelper($themePath);
    $url = $helper->asset('src/images/logo.png');

    expect($url)->toContain('logo-xyz.png');

    @unlink($themePath . '/dist/.vite/manifest.json');
    @rmdir($themePath . '/dist/.vite');
    @rmdir($themePath . '/dist');
    @rmdir($themePath);
});

test('FrontendConfig exposes entries and refresh paths', function () {
    $themePath = sys_get_temp_dir() . '/pinoox-fe-entries-' . uniqid();
    mkdir($themePath, 0777, true);
    file_put_contents($themePath . '/package.json', json_encode(['dependencies' => ['vue' => '^3.5.0']]));
    file_put_contents($themePath . '/frontend.config.php', <<<'PHP'
<?php
return [
    'stack' => 'vue',
    'entries' => ['src/main.js', 'src/admin.js'],
    'refresh' => false,
];
PHP);

    $config = FrontendConfig::forThemePath($themePath);

    expect(FrontendConfig::entries($config))->toBe(['src/main.js', 'src/admin.js'])
        ->and(FrontendConfig::refreshPaths($config))->toBe([]);

    @unlink($themePath . '/frontend.config.php');
    @unlink($themePath . '/package.json');
    @rmdir($themePath);
});

