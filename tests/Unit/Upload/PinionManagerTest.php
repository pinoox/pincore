<?php



use Pinoox\Pinion\Config;

use Pinoox\Pinion\Manager;

use Pinoox\Pinion\Session;

use Pinoox\Pinion\Store;

use Pinoox\Pinion\Support\NativePathResolver;

use Tests\Support\TestSandbox;



beforeEach(function () {

    TestSandbox::cleanup();

});



it('initializes a pinion session', function () {

    $root = TestSandbox::ensure('pinion-uploads');

    $manager = new Manager(new Store($root));



    $result = $manager->init('demo.pinx', 12 * 1024 * 1024, 'sandbox/chunk-target', ['pinx'], 5 * 1024 * 1024);



    expect($result->success)->toBeTrue()

        ->and($result->session)->toBeInstanceOf(Session::class)

        ->and($result->session->toArray()['protocol'])->toBe('pinion')

        ->and($result->session->total_chunks)->toBe(3);

});



it('receives chunks and completes an upload', function () {

    $root = TestSandbox::ensure('pinion-complete');

    $target = TestSandbox::ensure('pinion-target');

    $manager = new Manager(

        new Store($root),

        ['storage_strategy' => 'parts', 'verify_chunks' => false],

        new NativePathResolver(TestSandbox::root()),

    );



    $payload = 'hello-pinion';

    $result = $manager->init('package.pinx', strlen($payload), 'pinion-target', ['pinx'], 1024);

    $uploadId = $result->session->id;



    expect($manager->receive($uploadId, 0, $payload)->success)->toBeTrue();



    $complete = $manager->complete($uploadId);

    $finalPath = $target . '/package.pinx';



    expect($complete->success)->toBeTrue()

        ->and($complete->path)->toBe($finalPath)

        ->and(file_get_contents($finalPath))->toBe($payload);



    @unlink($finalPath);

});



it('resumes sessions by fingerprint', function () {

    $root = TestSandbox::ensure('pinion-resume');

    $manager = new Manager(new Store($root), ['verify_chunks' => false]);



    $first = $manager->init('demo.pinx', 100, 'out', ['pinx'], 100, null, 'fp-1');

    $second = $manager->init('demo.pinx', 100, 'out', ['pinx'], 100, null, 'fp-1');



    expect($second->resumed)->toBeTrue()

        ->and($second->session->id)->toBe($first->session->id);

});



it('parses chunk size units', function () {

    expect(Config::parseSize('5MB'))->toBe(5 * 1024 * 1024)

        ->and(Config::parseSize('512KB'))->toBe(512 * 1024);

});

