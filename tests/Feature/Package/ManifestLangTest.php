<?php

use Pinoox\Component\Package\ManifestLabel;
use Pinoox\Component\Package\ManifestLangLoader;
use Pinoox\Component\Package\ManifestLangRef;
use Pinoox\Component\Test\AppTestKit;
use Pinoox\Portal\App\AppEngine;

beforeEach(function () {
    AppTestKit::boot();
    manifestLangDeleteApp('com_test_manifest_lang');
    AppEngine::__rebuild();
});

afterEach(function () {
    manifestLangDeleteApp('com_test_manifest_lang');
    AppEngine::__rebuild();
});

it('resolves app manifest labels from lang files via @ refs', function () {
    manifestLangWriteApp([
        'app.php' => manifestLangAppPhp([
            'package' => 'com_test_manifest_lang',
            'name' => 'shop',
            'title' => '@manifest.title',
            'description' => '@manifest.description',
            'lang' => 'fa',
            'router' => ['routes' => []],
        ]),
        'lang/en/manifest.lang.php' => manifestLangPhp([
            'title' => 'Shop',
            'description' => 'E-commerce app',
        ]),
        'lang/fa/manifest.lang.php' => manifestLangPhp([
            'title' => 'فروشگاه',
            'description' => 'اپ فروشگاهی',
        ]),
    ]);

    AppEngine::__rebuild();

    expect(\Pinoox\Component\Package\AppManifest::displayName('com_test_manifest_lang', 'fa'))
        ->toBe('فروشگاه')
        ->and(\Pinoox\Component\Package\AppManifest::description('com_test_manifest_lang', 'en'))
        ->toBe('E-commerce app')
        ->and(\Pinoox\Component\Package\AppManifest::labels('com_test_manifest_lang')['title'])
        ->toBe(['en' => 'Shop', 'fa' => 'فروشگاه']);
});

it('resolves theme manifest labels from theme/lang with app fallback', function () {
    manifestLangWriteApp([
        'app.php' => manifestLangAppPhp([
            'package' => 'com_test_manifest_lang',
            'name' => 'shop',
            'title' => '@manifest.title',
            'lang' => 'en',
            'theme' => 'spark',
            'router' => ['routes' => []],
        ]),
        'lang/en/manifest.lang.php' => manifestLangPhp([
            'title' => 'Shop App',
            'description' => 'App description',
        ]),
        'theme/spark/theme.php' => manifestLangThemePhp([
            'name' => 'spark',
            'package' => 'com_test_manifest_lang',
            'title' => '@manifest.title',
            'description' => '@manifest.description',
        ]),
        'theme/spark/lang/en/manifest.lang.php' => manifestLangPhp([
            'title' => 'Spark Theme',
            'description' => 'Theme description',
        ]),
    ]);

    AppEngine::__rebuild();

    $manifest = \Pinoox\Component\Template\Theme\ThemeManifest::load('com_test_manifest_lang', 'spark');

    expect($manifest)->not->toBeNull()
        ->and($manifest->title('en'))->toBe('Spark Theme')
        ->and($manifest->description('en'))->toBe('Theme description')
        ->and($manifest->labels()['title'])->toBe(['en' => 'Spark Theme']);
});

it('keeps locale map support for legacy theme manifests', function () {
    manifestLangWriteApp([
        'theme/toranj/theme.php' => manifestLangThemePhp([
            'name' => 'toranj',
            'package' => 'com_test_manifest_lang',
            'title' => ['en' => 'Toranj', 'fa' => 'ترنج'],
            'description' => ['en' => 'Minimal blog template'],
        ]),
    ]);

    AppEngine::__rebuild();

    $manifest = \Pinoox\Component\Template\Theme\ThemeManifest::load('com_test_manifest_lang', 'toranj');

    expect($manifest)->not->toBeNull()
        ->and($manifest->title('fa'))->toBe('ترنج');
});

function manifestLangAppPhp(array $data): string
{
    return "<?php\n\nreturn " . var_export($data, true) . ";\n";
}

function manifestLangThemePhp(array $data): string
{
    return manifestLangAppPhp($data);
}

function manifestLangPhp(array $data): string
{
    return "<?php\n\nreturn " . var_export($data, true) . ";\n";
}

function manifestLangWriteApp(array $files): void
{
    $package = 'com_test_manifest_lang';
    $payload = [];

    foreach ($files as $relative => $content) {
        $payload[$relative] = $content;
    }

    if (!isset($payload['app.php'])) {
        $payload['app.php'] = manifestLangAppPhp([
            'package' => $package,
            'enable' => true,
            'name' => 'test',
            'theme' => 'default',
            'router' => ['routes' => []],
        ]);
    }

    AppTestKit::fakeApp($package, $payload);
}

function manifestLangDeleteApp(string $package): void
{
    AppTestKit::deleteFakeApp($package);
}
