<?php

use Pinoox\Component\Kernel\Loader;
use Pinoox\Support\ProjectCli;

beforeEach(function () {
    Loader::setBasePath(testProjectRoot());
});

it('prefers the root pinoox script when present', function () {
    $root = sys_get_temp_dir() . '/pinoox-project-cli-' . uniqid('', true);
    mkdir($root, 0777, true);
    file_put_contents($root . '/pinoox', "<?php\n");

    try {
        expect(ProjectCli::script($root))->toBe(str_replace('\\', '/', $root . '/pinoox'));
    } finally {
        @unlink($root . '/pinoox');
        @rmdir($root);
    }
});

it('falls back to platform launcher bootstrap for standalone hosts', function () {
    $root = sys_get_temp_dir() . '/pinoox-project-cli-' . uniqid('', true);
    $bootstrap = $root . '/platform/launcher';
    mkdir($bootstrap, 0777, true);
    file_put_contents($bootstrap . '/bootstrap.php', "<?php\n");

    try {
        expect(ProjectCli::script($root))
            ->toBe(str_replace('\\', '/', $bootstrap . '/bootstrap.php'))
            ->and(ProjectCli::invoke($root))
            ->toBe('php platform/launcher/bootstrap.php')
            ->and(ProjectCli::isCliScript($bootstrap . '/bootstrap.php'))->toBeTrue();
    } finally {
        @unlink($bootstrap . '/bootstrap.php');
        @rmdir($bootstrap);
        @rmdir(dirname($bootstrap));
        @rmdir($root . '/platform');
        @rmdir($root);
    }
});

it('builds nested process commands with the resolved script', function () {
    $root = sys_get_temp_dir() . '/pinoox-project-cli-' . uniqid('', true);
    $bootstrap = $root . '/platform/launcher';
    mkdir($bootstrap, 0777, true);
    file_put_contents($bootstrap . '/bootstrap.php', "<?php\n");

    try {
        $command = ProjectCli::processCommand(['serve', '--app=com_test'], $root);

        expect($command[1])->toBe(str_replace('\\', '/', $bootstrap . '/bootstrap.php'))
            ->and($command[2])->toBe('serve')
            ->and($command[3])->toBe('--app=com_test')
            ->and(ProjectCli::format('dev com_test', $root))
            ->toBe('php platform/launcher/bootstrap.php dev com_test');
    } finally {
        @unlink($bootstrap . '/bootstrap.php');
        @rmdir($bootstrap);
        @rmdir(dirname($bootstrap));
        @rmdir($root . '/platform');
        @rmdir($root);
    }
});

it('formats pinx commands with the pinx binary when available', function () {
    $root = sys_get_temp_dir() . '/pinoox-project-cli-' . uniqid('', true);
    mkdir($root . '/bin', 0777, true);
    file_put_contents($root . '/bin/pinx', "#!/usr/bin/env php\n");

    try {
        expect(ProjectCli::pinxInvoke($root))->toBe('pinx')
            ->and(ProjectCli::pinxFormat('dev com_test', $root))->toBe('pinx dev com_test')
            ->and(ProjectCli::pinxProcessCommand(['pinx:install', 'demo.pinx'], $root)[1])
            ->toBe(str_replace('\\', '/', $root . '/bin/pinx'));
    } finally {
        @unlink($root . '/bin/pinx');
        @rmdir($root . '/bin');
        @rmdir($root);
    }
});

it('builds mixed help examples for pinx and platform commands', function () {
    $root = sys_get_temp_dir() . '/pinoox-project-cli-' . uniqid('', true);
    mkdir($root . '/bin', 0777, true);
    file_put_contents($root . '/bin/pinx', "#!/usr/bin/env php\n");
    mkdir($root . '/platform/launcher', 0777, true);
    file_put_contents($root . '/platform/launcher/bootstrap.php', "<?php\n");

    try {
        $block = ProjectCli::examplesBlock([
            [ProjectCli::SCOPE_PINX, 'dev'],
            [ProjectCli::SCOPE_PINOOX, 'serve'],
        ], $root);

        expect($block)->toContain('pinx dev')
            ->and($block)->toContain('php platform/launcher/bootstrap.php serve');
    } finally {
        @unlink($root . '/bin/pinx');
        @unlink($root . '/platform/launcher/bootstrap.php');
        @rmdir($root . '/bin');
        @rmdir($root . '/platform/launcher');
        @rmdir($root . '/platform');
        @rmdir($root);
    }
});
