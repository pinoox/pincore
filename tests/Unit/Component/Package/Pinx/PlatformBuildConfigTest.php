<?php

use Pinoox\Component\Package\Pinx\PlatformBuildConfig;

it('merges pinroll build exclude and include over platform/build.config.php', function () {
    $root = sys_get_temp_dir() . '/pincore-pinroll-build-' . bin2hex(random_bytes(4));
    mkdir($root . '/platform', 0755, true);
    mkdir($root . '/.pinoox', 0755, true);

    file_put_contents($root . '/platform/build.config.php', <<<'PHP'
<?php
return [
    'exclude' => ['tests'],
    'include' => ['keep-me'],
];
PHP);

    file_put_contents($root . '/.pinoox/pinroll.config.php', <<<'PHP'
<?php
return [
    'build' => [
        'exclude' => ['docs'],
        'include' => ['force-in'],
    ],
];
PHP);

    $raw = PlatformBuildConfig::rawConfig($root);

    expect($raw['exclude'])->toContain('tests')
        ->and($raw['exclude'])->toContain('docs')
        ->and($raw['include'])->toContain('keep-me')
        ->and($raw['include'])->toContain('force-in');

    @unlink($root . '/platform/build.config.php');
    @unlink($root . '/.pinoox/pinroll.config.php');
    @rmdir($root . '/platform');
    @rmdir($root . '/.pinoox');
    @rmdir($root);
});
