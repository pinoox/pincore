<?php

use PhpZip\ZipFile;
use Pinoox\Component\Package\Pinx\PinxReader;
use Pinoox\Component\Test\AppTestKit;

it('resolves legacy pin app.php lang refs from archive lang files', function () {
    $zipPath = pinxCliTempFile('legacy_app_lang.pinx');
    $appPhp = <<<'PHP'
<?php
return [
    'package' => 'com_test_pinx_lang',
    'name' => 'shop',
    'title' => '@manifest.title',
    'description' => '@manifest.description',
    'version-name' => '1.0',
    'version-code' => 1,
];
PHP;
    $langEn = <<<'PHP'
<?php
return [
    'title' => 'Shop',
    'description' => 'E-commerce app',
];
PHP;
    $langFa = <<<'PHP'
<?php
return [
    'title' => 'فروشگاه',
    'description' => 'اپ فروشگاهی',
];
PHP;

    $zip = new ZipFile();
    $zip->addFromString('app.php', $appPhp);
    $zip->addFromString('lang/en/manifest.lang.php', $langEn);
    $zip->addFromString('lang/fa/manifest.lang.php', $langFa);
    $zip->saveAsFile($zipPath);
    $zip->close();

    $reader = new PinxReader();
    $reader->open($zipPath);
    $manifest = $reader->manifest();
    $reader->close();

    expect($manifest->title('fa'))->toBe('فروشگاه')
        ->and($manifest->description('en'))->toBe('E-commerce app')
        ->and($manifest->labels()['title'])->toBe(['en' => 'Shop', 'fa' => 'فروشگاه']);

    @unlink($zipPath);
});

function pinxCliTempFile(string $name): string
{
    $dir = testFixtures('pinx_cli');

    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir . '/' . $name;
}
