<?php

use Illuminate\Database\Eloquent\Collection;
use Pinoox\Component\Database\Factories\Factory;

beforeEach(function () {
    if (!class_exists('App\com_test_factory\Model\PostModel')) {
        eval('namespace App\com_test_factory\Model; class PostModel extends \Pinoox\Component\Database\Model { public $timestamps = false; protected $guarded = []; }');
    }

    if (!class_exists('App\com_test_factory\database\factories\PostFactory')) {
        eval('namespace App\com_test_factory\database\factories; class PostFactory extends \Pinoox\Component\Database\Factories\Factory { protected ?string $model = \App\com_test_factory\Model\PostModel::class; public function definition(): array { return ["title" => "Untitled", "status" => "draft"]; } }');
    }
});

it('builds model instances through pinoox factories', function () {
    $post = App\com_test_factory\Model\PostModel::factory()
        ->state(['status' => 'published'])
        ->make(['title' => 'Hello']);

    expect($post)->toBeInstanceOf(App\com_test_factory\Model\PostModel::class)
        ->and($post->title)->toBe('Hello')
        ->and($post->status)->toBe('published');
});

it('supports counts and sequences', function () {
    $posts = App\com_test_factory\Model\PostModel::factory()
        ->count(3)
        ->sequence(
            ['status' => 'draft'],
            ['status' => 'published'],
        )
        ->make();

    expect($posts)->toBeInstanceOf(Collection::class)
        ->and($posts)->toHaveCount(3)
        ->and($posts[0]->status)->toBe('draft')
        ->and($posts[1]->status)->toBe('published')
        ->and($posts[2]->status)->toBe('draft');
});

it('resolves factories for model classes', function () {
    $factory = Factory::factoryForModel(App\com_test_factory\Model\PostModel::class);

    expect($factory)->toBeInstanceOf(App\com_test_factory\database\factories\PostFactory::class);
});
