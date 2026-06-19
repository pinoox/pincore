<?php

declare(strict_types=1);

putenv('APP_ENV=test');
putenv('DB_CONNECTION=sqlite');
$_ENV['APP_ENV'] = 'test';
$_SERVER['APP_ENV'] = 'test';
$_ENV['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_CONNECTION'] = 'sqlite';

require __DIR__ . '/bootstrap.php';

use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\Mode;
use Pinoox\Component\Test\AppTestKit;

echo 'BASE=' . PINOOX_BASE_PATH . PHP_EOL;
echo 'CORE=' . PINOOX_CORE_PATH . PHP_EOL;
echo 'debug=' . (Mode::debug() ? 'true' : 'false') . PHP_EOL;
echo 'mode=' . Mode::name() . PHP_EOL;

AppTestKit::boot();
echo 'after boot debug=' . (Mode::debug() ? 'true' : 'false') . PHP_EOL;
