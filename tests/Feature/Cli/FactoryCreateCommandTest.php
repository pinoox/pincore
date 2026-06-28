<?php

use Pinoox\Component\Test\AppTestKit;
use Pinoox\Terminal\Factory\FactoryCreateCommand;
use Symfony\Component\Console\Tester\CommandTester;

afterEach(function () {
    AppTestKit::deleteFakeApp('com_test_factory_command');
});

it('creates a model factory file in the selected app', function () {
    $package = 'com_test_factory_command';
    AppTestKit::fakeApp($package, [
        'Model/ProductModel.php' => "<?php\n\nnamespace App\\{$package}\\Model;\n\nclass ProductModel extends \\Pinoox\\Component\\Database\\Model {}\n",
    ]);

    $tester = new CommandTester(new FactoryCreateCommand());
    $status = $tester->execute([
        'factory' => 'Product',
        'package' => $package,
    ], ['interactive' => false]);

    $path = AppTestKit::path($package, 'database/factories/ProductFactory.php');

    expect($status)->toBe(0)
        ->and(is_file($path))->toBeTrue()
        ->and(file_get_contents($path))->toContain('class ProductFactory extends Factory')
        ->and(file_get_contents($path))->toContain('use App\\' . $package . '\\Model\\ProductModel;')
        ->and(file_get_contents($path))->toContain('protected ?string $model = ProductModel::class;');
});
