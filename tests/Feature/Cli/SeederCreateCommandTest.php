<?php

use Pinoox\Component\Test\AppTestKit;
use Pinoox\Terminal\Seeder\SeederCreateCommand;
use Symfony\Component\Console\Tester\CommandTester;

afterEach(function () {
    AppTestKit::deleteFakeApp('com_test_seeder_command');
});

it('creates seeder files in database seeders directory', function () {
    $package = 'com_test_seeder_command';
    AppTestKit::fakeApp($package);

    $tester = new CommandTester(new SeederCreateCommand());
    $status = $tester->execute([
        'seeder' => 'Demo',
        'package' => $package,
    ], ['interactive' => false]);

    $path = AppTestKit::path($package, 'database/seeders/DemoSeeder.php');

    expect($status)->toBe(0)
        ->and(is_file($path))->toBeTrue()
        ->and(file_get_contents($path))->toContain('namespace App\\' . $package . '\\database\\seeders;');
});
