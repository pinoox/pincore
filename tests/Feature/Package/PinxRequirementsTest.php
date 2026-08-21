<?php

use Pinoox\Component\Kernel\Exception;
use Pinoox\Component\Package\Pinx\PinxBuildConfig;
use Pinoox\Component\Package\Pinx\PinxBuilder;
use Pinoox\Component\Package\Pinx\PinxInstaller;
use Pinoox\Component\Package\Pinx\PinxManifest;
use Pinoox\Component\Package\Pinx\PinxReader;
use Pinoox\Component\Package\Pinx\PinxRequirements;
use Pinoox\Component\Package\Pinx\PinxSignKey;
use Pinoox\Component\Test\AppTestKit;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Support\SystemConfig;
use Pinoox\Terminal\Pinx\PinxInfoCommand;
use Symfony\Component\Console\Tester\CommandTester;

const PINX_REQUIREMENTS_TEST_PACKAGE = 'com_test_pinx_requirements';

beforeEach(function () {
    pinxRequirementsDeleteApp();
    pinxRequirementsCleanupArtifacts();
    AppEngine::__rebuild();
});

afterEach(function () {
    pinxRequirementsDeleteApp();
    pinxRequirementsCleanupArtifacts();
    AppEngine::__rebuild();
});

it('accepts the supported PHP requirement grammar', function () {
    expect(PinxRequirements::normalize(['php' => '>=8.3']))
        ->toBe(['php' => '>=8.3'])
        ->and(PinxRequirements::normalize(['php' => '>=8.3.0']))
        ->toBe(['php' => '>=8.3.0']);
});

it('rejects unsupported PHP requirement grammar', function (string $constraint) {
    expect(fn () => PinxRequirements::normalize(['php' => $constraint]))
        ->toThrow(Exception::class, 'Unsupported PHP requirement constraint');
})->with([
    '^8.3',
    '~8.3',
    '8.3.*',
    '>=8.2 <8.4',
    '>=8.2 || >=8.3',
]);

it('rejects non-object requirements and unknown keys', function () {
    $nonArray = PinxManifest::fromArray([
        'format' => PinxManifest::FORMAT,
        'type' => PinxManifest::TYPE_APP,
        'package' => PINX_REQUIREMENTS_TEST_PACKAGE,
        'requirements' => 'php>=8.3',
    ]);

    expect(fn () => $nonArray->validate())
        ->toThrow(Exception::class, 'Pinx requirements must be an object.');

    $unknown = PinxManifest::fromArray([
        'format' => PinxManifest::FORMAT,
        'type' => PinxManifest::TYPE_APP,
        'package' => PINX_REQUIREMENTS_TEST_PACKAGE,
        'requirements' => ['node' => '>=20'],
    ]);

    expect(fn () => $unknown->validate())
        ->toThrow(Exception::class, 'Unsupported package requirement: node');
});

it('evaluates PHP requirements deterministically', function () {
    $manifest = PinxManifest::fromArray([
        'requirements' => ['php' => '>=8.3'],
    ]);

    $passing = PinxRequirements::inspect($manifest, '8.3.0');
    $failing = PinxRequirements::inspect($manifest, '8.2.99');

    expect($passing['satisfied'])->toBeTrue()
        ->and($passing['checks'][0]['satisfied'])->toBeTrue()
        ->and($failing['satisfied'])->toBeFalse()
        ->and($failing['checks'][0]['satisfied'])->toBeFalse()
        ->and($failing['errors'][0])->toContain('requires PHP >=8.3');
});

it('keeps legacy minpin unchanged when requirements are empty', function () {
    pinxRequirementsWriteApp([
        'pinx' => [
            'type' => 'app',
            'minpin' => 17,
        ],
    ]);

    $build = pinxRequirementsBuild();
    $reader = new PinxReader();
    $reader->open($build['path']);
    $manifest = $reader->manifest();

    expect($manifest->minpin())->toBe(17)
        ->and($manifest->requirements())->toBe([]);

    $reader->close();
});

it('raises effective minpin to the requirements capability floor', function () {
    pinxRequirementsWriteApp([
        'pinx' => [
            'type' => 'app',
            'minpin' => 0,
            'requirements' => ['php' => '>=8.0'],
        ],
    ]);

    $build = pinxRequirementsBuild();
    $reader = new PinxReader();
    $reader->open($build['path']);
    $manifest = $reader->manifest();

    expect($manifest->requirements())->toBe(['php' => '>=8.0'])
        ->and($manifest->minpin())->toBe(PinxRequirements::MIN_KERNEL_CODE)
        ->and($manifest->minpin())->toBeGreaterThanOrEqual(230);

    $reader->close();
});

it('keeps build preview and final manifest runtime requirements aligned', function () {
    pinxRequirementsWriteApp([
        'pinx' => [
            'type' => 'app',
            'minpin' => 12,
            'requirements' => ['php' => '>=8.0'],
        ],
    ]);

    $engine = AppEngine::___();
    $buildConfig = PinxBuildConfig::resolve($engine, PINX_REQUIREMENTS_TEST_PACKAGE);
    $preview = PinxManifest::fromAppConfig(
        PinxBuildConfig::appConfigArray($engine, PINX_REQUIREMENTS_TEST_PACKAGE),
        $buildConfig['type'],
        $buildConfig,
    );

    $build = pinxRequirementsBuild();
    $final = $build['manifest'];

    expect($preview->requirements())->toBe($final->requirements())
        ->and($preview->minpin())->toBe($final->minpin())
        ->and($final->minpin())->toBe(PinxRequirements::MIN_KERNEL_CODE);
});

it('installs when the PHP requirement is satisfied', function () {
    pinxRequirementsWriteApp([
        'pinx' => [
            'type' => 'app',
            'requirements' => ['php' => '>=8.0'],
        ],
    ]);

    $build = pinxRequirementsBuild();

    pinxRequirementsDeleteApp();
    AppEngine::__rebuild();

    $result = pinxRequirementsInstall($build['path']);
    $phpStep = pinxRequirementsStep($result->steps, 'php');

    expect($result->success)->toBeTrue()
        ->and($phpStep)->not->toBeNull()
        ->and($phpStep['status'])->toBe('ok')
        ->and(is_dir(AppTestKit::path(PINX_REQUIREMENTS_TEST_PACKAGE)))->toBeTrue();
});

it('rejects an incompatible fresh install before extraction even with force', function () {
    pinxRequirementsWriteApp([
        'pinx' => [
            'type' => 'app',
            'requirements' => ['php' => '>=99.0'],
        ],
    ]);

    $build = pinxRequirementsBuild();

    pinxRequirementsDeleteApp();
    AppEngine::__rebuild();

    $result = pinxRequirementsInstall($build['path'], ['force' => true]);

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('requires PHP >=99.0')
        ->and(pinxRequirementsStep($result->steps, 'extract'))->toBeNull()
        ->and(is_dir(AppTestKit::path(PINX_REQUIREMENTS_TEST_PACKAGE)))->toBeFalse();
});

it('leaves an installed app untouched when an incompatible update is rejected', function () {
    pinxRequirementsWriteApp([
        'version-code' => 2,
        'name' => 'incompatible-update',
        'pinx' => [
            'type' => 'app',
            'requirements' => ['php' => '>=99.0'],
        ],
    ], 'update-payload');

    $build = pinxRequirementsBuild();

    pinxRequirementsDeleteApp();
    pinxRequirementsWriteApp([
        'version-code' => 1,
        'name' => 'installed-version',
    ], 'installed-payload');
    AppEngine::__rebuild();

    $result = pinxRequirementsInstall($build['path'], ['force' => true]);
    $installed = include AppTestKit::path(PINX_REQUIREMENTS_TEST_PACKAGE, 'app.php');
    $marker = file_get_contents(AppTestKit::path(PINX_REQUIREMENTS_TEST_PACKAGE, 'marker.txt'));

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('requires PHP >=99.0')
        ->and($installed['version-code'])->toBe(1)
        ->and($installed['name'])->toBe('installed-version')
        ->and($marker)->toBe('installed-payload');
});

it('includes requirements in signed manifest and detects requirement tampering', function () {
    pinxRequirementsWriteApp([
        'pinx' => [
            'type' => 'app',
            'requirements' => ['php' => '>=8.0'],
        ],
    ]);
    pinxRequirementsGenerateKey();

    $build = pinxRequirementsBuild();
    $reader = new PinxReader();
    $reader->open($build['path']);

    $signature = $reader->signature();
    $manifestJson = $reader->manifestJson();

    expect($signature)->not->toBeNull()
        ->and($reader->manifest()->requirements())->toBe(['php' => '>=8.0'])
        ->and($signature['manifest_sha256'] ?? null)->toBe(hash('sha256', $manifestJson));

    $reader->close();

    $manifestData = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
    $manifestData['requirements']['php'] = '>=8.1';
    $tamperedJson = json_encode(
        $manifestData,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );

    $archive = new ZipArchive();
    $archive->open($build['path']);
    $archive->addFromString(PinxManifest::MANIFEST_FILE, $tamperedJson);
    $archive->close();

    $result = pinxRequirementsInstall($build['path']);

    expect($result->success)->toBeFalse()
        ->and($result->message)->toContain('Manifest was modified after signing.');
});

it('shows PHP requirement through pinx info', function () {
    pinxRequirementsWriteApp([
        'pinx' => [
            'type' => 'app',
            'requirements' => ['php' => '>=8.0'],
        ],
    ]);

    $build = pinxRequirementsBuild();
    $tester = new CommandTester(new PinxInfoCommand());
    $status = $tester->execute(['package' => $build['path']]);

    expect($status)->toBe(0)
        ->and($tester->getDisplay())->toContain('PHP requirement')
        ->and($tester->getDisplay())->toContain('>=8.0');
});

function pinxRequirementsWriteApp(array $extra = [], string $marker = 'source'): void
{
    $config = array_merge([
        'package' => PINX_REQUIREMENTS_TEST_PACKAGE,
        'enable' => true,
        'name' => 'Pinx Requirements Test',
        'version-name' => '1.0.1',
        'version-code' => 2,
        'theme' => 'default',
    ], $extra);

    AppTestKit::fakeApp(PINX_REQUIREMENTS_TEST_PACKAGE, [
        'app.php' => "<?php\n\nreturn " . var_export($config, true) . ";\n",
        'marker.txt' => $marker,
        'theme/default/theme.txt' => 'default',
    ]);
}

function pinxRequirementsBuild(array $options = []): array
{
    $dir = testFixtures('pinx-requirements');
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $output = $dir . '/package_' . uniqid('', true) . '.pinx';

    return (new PinxBuilder(AppEngine::___()))
        ->build(PINX_REQUIREMENTS_TEST_PACKAGE, $output, $options);
}

function pinxRequirementsInstall(string $path, array $options = [])
{
    return (new PinxInstaller(AppEngine::___(), SystemConfig::path('wizard_tmp')))
        ->install($path, array_merge([
            'skip_migrate' => true,
            'skip_patch' => true,
            'skip_lifecycle' => true,
            'skip_cache' => true,
        ], $options));
}

function pinxRequirementsGenerateKey(): string
{
    bootstrapTestSodiumCompat();

    $dir = AppTestKit::path(PINX_REQUIREMENTS_TEST_PACKAGE, 'pinx');
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $path = $dir . '/' . PinxSignKey::KEY_FILE;
    PinxSignKey::save(PinxSignKey::generate(PINX_REQUIREMENTS_TEST_PACKAGE), $path);

    return $path;
}

function pinxRequirementsDeleteApp(): void
{
    AppTestKit::deleteFakeApp(PINX_REQUIREMENTS_TEST_PACKAGE);
}

function pinxRequirementsCleanupArtifacts(): void
{
    $dir = testFixtures('pinx-requirements');
    if (!is_dir($dir)) {
        return;
    }

    foreach (glob($dir . '/*') ?: [] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * @param list<array{step: string, status: string, message: string}> $steps
 * @return array{step: string, status: string, message: string}|null
 */
function pinxRequirementsStep(array $steps, string $name): ?array
{
    foreach ($steps as $step) {
        if (($step['step'] ?? null) === $name) {
            return $step;
        }
    }

    return null;
}
