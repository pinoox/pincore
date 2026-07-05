<?php

use function Pinoox\Router\route_files;

it('resolves route file paths relative to the caller route file', function () {
    $base = testSandbox('route_files_helper');

    if (!is_dir($base)) {
        mkdir($base, 0777, true);
    }

    $directory = $base . '/api';
    mkdir($directory, 0777, true);
    touch($directory . '/public.php');
    touch($directory . '/private.php');

    expect(route_files('api', $base))->toBe(str_replace('\\', '/', $base . '/api'))
        ->and(route_files(['api/public.php', 'api/private.php'], $base))->toBe([
            str_replace('\\', '/', $base . '/api/public.php'),
            str_replace('\\', '/', $base . '/api/private.php'),
        ]);
});
