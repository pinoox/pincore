<?php
use Pinoox\Portal\Hash;
it('declares the Hash portal contract', function () {
    expectPortalContract(Hash::class);
});

it('preserves existing container configuration when Hash registers', function () {
    $container = \Pinoox\Component\Kernel\Container::Illuminate();
    $container->instance('config', new \Pinoox\Support\IlluminateConfigRepository([
        'database' => [
            'connections' => [
                'existing' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ],
    ]));

    // Register/call Hash
    Hash::__register();

    $config = $container->make('config');

    expect($config)->toBeInstanceOf(\Pinoox\Support\IlluminateConfigRepository::class)
        ->and($config)->toBeInstanceOf(\ArrayAccess::class)
        ->and($config['database.connections.existing.driver'])->toBe('sqlite')
        ->and(isset($config['hashing']))->toBeTrue();
});

it('allows DatabaseManager to resolve connections via array access after Hash is used', function () {
    $container = \Pinoox\Component\Kernel\Container::Illuminate();

    // Use Hash
    $hashed = Hash::make('secret-pass');
    expect(Hash::check('secret-pass', $hashed))->toBeTrue();

    // Now initialize DatabaseManager with the same Illuminate container
    $dbManager = new \Pinoox\Component\Database\DatabaseManager($container);
    $dbManager->addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'hash_test_',
    ], 'hash_conn');

    $conn = $dbManager->getDatabaseManager()->connection('hash_conn');

    expect($conn->getName())->toBe('hash_conn')
        ->and($container['config']['database.connections.hash_conn.driver'])->toBe('sqlite');
});


