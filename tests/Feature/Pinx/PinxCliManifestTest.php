<?php

use PhpZip\ZipFile;
use Pinoox\Component\Kernel\Exception;
use Pinoox\Component\Package\Pinx\PinxManifest;
use Pinoox\Component\Package\Pinx\PinxReader;

it('resolves manifest.json labels for CLI locale', function () {
    $zipPath = pinxCliTempFile('manifest_labels.pinx');
    $manifest = [
        'format' => PinxManifest::FORMAT,
        'format_version' => PinxManifest::FORMAT_VERSION,
        'type' => PinxManifest::TYPE_APP,
        'package' => 'com_test_pinx_lang',
        'name' => 'Shop',
        'description' => 'E-commerce app',
        'labels' => [
            'title' => ['en' => 'Shop', 'fa' => 'فروشگاه'],
            'description' => ['en' => 'E-commerce app', 'fa' => 'اپ فروشگاهی'],
        ],
        'version_name' => '1.0',
        'version_code' => 1,
        'minpin' => 0,
    ];

    $zip = new ZipFile();
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
    $zip->addFromString('payload/app.php', "<?php\nreturn ['package' => 'com_test_pinx_lang'];");
    $zip->saveAsFile($zipPath);
    $zip->close();

    $reader = new PinxReader();
    $reader->open($zipPath);
    $loaded = $reader->manifest();
    $reader->close();

    expect($loaded->title('fa'))->toBe('فروشگاه')
        ->and($loaded->description('en'))->toBe('E-commerce app')
        ->and($loaded->labels()['title'])->toBe(['en' => 'Shop', 'fa' => 'فروشگاه']);

    @unlink($zipPath);
});

it('rejects packages without manifest.json', function () {
    $zipPath = pinxCliTempFile('legacy_app_only.pinx');
    $zip = new ZipFile();
    $zip->addFromString('app.php', "<?php\nreturn ['package' => 'com_legacy'];");
    $zip->saveAsFile($zipPath);
    $zip->close();

    $reader = new PinxReader();

    expect(fn () => $reader->open($zipPath))
        ->toThrow(Exception::class, 'manifest.json not found');

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
