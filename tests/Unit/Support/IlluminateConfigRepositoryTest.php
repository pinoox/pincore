<?php

use Pinoox\Support\IlluminateConfigRepository;

it('supports ArrayAccess and dot notation access', function () {
    $repo = new IlluminateConfigRepository([
        'database' => [
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                ],
            ],
        ],
    ]);

    expect(isset($repo['database.default']))->toBeTrue()
        ->and($repo['database.default'])->toBe('sqlite')
        ->and(isset($repo['database.connections.sqlite.driver']))->toBeTrue()
        ->and($repo['database.connections.sqlite.driver'])->toBe('sqlite')
        ->and($repo['database.connections']['sqlite']['driver'])->toBe('sqlite');

    $repo['database.connections.mysql'] = [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
    ];

    expect(isset($repo['database.connections.mysql']))->toBeTrue()
        ->and($repo['database.connections.mysql.host'])->toBe('127.0.0.1');

    unset($repo['database.connections.mysql']);

    expect(isset($repo['database.connections.mysql']))->toBeFalse();
});

it('supports repository contract methods has, get, set, prepend, and push', function () {
    $repo = new IlluminateConfigRepository();

    $repo->set('hashing.driver', 'bcrypt');
    expect($repo->has('hashing.driver'))->toBeTrue()
        ->and($repo->get('hashing.driver'))->toBe('bcrypt')
        ->and($repo->get('hashing.unknown', 'fallback'))->toBe('fallback');

    $repo->set('services.tags', ['first']);
    $repo->push('services.tags', 'second');
    $repo->prepend('services.tags', 'zero');

    expect($repo->get('services.tags'))->toBe(['zero', 'first', 'second']);
});

it('normalizes literal dot keys on instantiation', function () {
    $repo = new IlluminateConfigRepository([
        'database.connections' => [
            'test' => ['driver' => 'sqlite'],
        ],
    ]);

    expect($repo['database.connections']['test']['driver'])->toBe('sqlite')
        ->and($repo['database.connections.test.driver'])->toBe('sqlite');
});
