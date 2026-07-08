<?php

use Pinoox\Component\Package\GitignorePathMatcher;
use Symfony\Component\Process\Process;

it('uses git check-ignore for nested theme pinoox files', function () {
    $root = realpath(dirname(__DIR__, 4));
    $absolute = $root . '/apps/com_pinoox_welcome/theme/welcome/.pinoox/dev.json';

    if (!is_file($absolute)) {
        test()->markTestSkipped('welcome theme .pinoox/dev.json not present');
    }

    $matcher = new GitignorePathMatcher($root);

    expect($matcher->usesGitEngine())->toBeTrue()
        ->and($matcher->isIgnored($absolute))->toBeTrue();
});

it('matches git check-ignore for negation rules inside a repository', function () {
    $root = sys_get_temp_dir() . '/gitignore_negation_' . uniqid('', true);
    mkdir($root . '/apps/com_allowed', 0777, true);
    mkdir($root . '/apps/com_blocked', 0777, true);
    file_put_contents($root . '/.gitignore', "/apps/*\n!/apps/com_allowed/\n!/apps/com_allowed/**\n");
    file_put_contents($root . '/apps/com_allowed/app.php', '<?php');
    file_put_contents($root . '/apps/com_blocked/app.php', '<?php');
    file_put_contents($root . '/index.php', '<?php');

    (new Process(['git', 'init'], $root))->mustRun();

    $matcher = new GitignorePathMatcher($root);
    $paths = [
        $root . '/index.php',
        $root . '/apps/com_allowed/app.php',
        $root . '/apps/com_blocked/app.php',
    ];

    expect($matcher->usesGitEngine())->toBeTrue()
        ->and($matcher->shouldUseFinderGitignore())->toBeFalse();

    foreach ($paths as $path) {
        $git = new Process(['git', '-C', $root, 'check-ignore', '-q', $path]);
        $git->run();

        expect($matcher->isIgnored($path))->toBe($git->getExitCode() === 0);
    }

    expect($matcher->filterIncludedPaths($paths))->toBe([
        $root . '/index.php',
        $root . '/apps/com_allowed/app.php',
    ]);

    gitignoreMatcherDeleteDirectory($root);
});

it('matches git check-ignore for storage skeleton negation rules', function () {
    $root = sys_get_temp_dir() . '/gitignore_storage_' . uniqid('', true);
    mkdir($root . '/storage/logs', 0777, true);
    mkdir($root . '/storage/apps/com_demo', 0777, true);
    file_put_contents($root . '/.gitignore', <<<'GITIGNORE'
/storage/*
!/storage/.gitkeep
!/storage/.htaccess
!/storage/**/.gitkeep
GITIGNORE);
    file_put_contents($root . '/storage/.htaccess', 'deny');
    file_put_contents($root . '/storage/.gitkeep', '');
    file_put_contents($root . '/storage/logs/app.log', 'log');
    file_put_contents($root . '/storage/apps/com_demo/.gitkeep', '');

    (new Process(['git', 'init'], $root))->mustRun();

    $matcher = new GitignorePathMatcher($root);
    $paths = [
        $root . '/storage/.htaccess',
        $root . '/storage/.gitkeep',
        $root . '/storage/apps/com_demo/.gitkeep',
        $root . '/storage/logs/app.log',
    ];

    foreach ($paths as $path) {
        $git = new Process(['git', '-C', $root, 'check-ignore', '-q', $path]);
        $git->run();

        expect($matcher->isIgnored($path))->toBe($git->getExitCode() === 0);
    }

    $included = $matcher->filterIncludedPaths($paths);
    $expectedIncluded = array_values(array_filter(
        $paths,
        static function (string $path) use ($root): bool {
            $git = new Process(['git', '-C', $root, 'check-ignore', '-q', $path]);
            $git->run();

            return $git->getExitCode() !== 0;
        },
    ));

    expect($included)->toBe($expectedIncluded);

    gitignoreMatcherDeleteDirectory($root);
});

function gitignoreMatcherDeleteDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $full = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($full)) {
            gitignoreMatcherDeleteDirectory($full);
            continue;
        }

        @unlink($full);
    }

    @rmdir($path);
}
