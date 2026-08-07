<?php

use Illuminate\Database\Eloquent\Collection;
use Pinoox\Component\Database\Factories\Factory;
use Pinoox\Component\Database\Factories\FactoryBase;
use Pinoox\Component\Database\Factories\FactoryToolkit;
use Pinoox\Component\Test\AppTestKit;

beforeEach(function () {
    FactoryToolkit::flush();

    if (!class_exists('App\com_test_factory\Model\PostModel')) {
        eval('namespace App\com_test_factory\Model; class PostModel extends \Pinoox\Component\Database\Model { public $timestamps = false; protected $guarded = []; }');
    }

    if (!class_exists('App\com_test_factory\database\factories\PostFactory')) {
        eval('namespace App\com_test_factory\database\factories; class PostFactory extends \Pinoox\Component\Database\Factories\Factory { protected ?string $model = \App\com_test_factory\Model\PostModel::class; public function definition(): array { return ["title" => "Untitled", "status" => "draft"]; } }');
    }
});

afterEach(function () {
    FactoryToolkit::flush();
    AppTestKit::cleanupTransientArtifacts(false);
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

it('loads anonymous FactoryBase files like SeederBase', function () {
    $package = 'com_test_factory_base';
    AppTestKit::fakeApp($package, [
        'Model/ArticleModel.php' => <<<PHP
<?php
namespace App\\{$package}\\Model;
use Pinoox\\Component\\Database\\Model;
class ArticleModel extends Model
{
    public \$timestamps = false;
    protected \$guarded = [];
}
PHP,
        'database/factories/ArticleFactory.php' => <<<PHP
<?php
namespace App\\{$package}\\database\\factories;
use App\\{$package}\\Model\\ArticleModel;
use Pinoox\\Component\\Database\\Factories\\FactoryBase;
return new class extends FactoryBase
{
    protected ?string \$model = ArticleModel::class;
    public function definition(): array
    {
        return [
            'title' => 'FactoryBase article',
            'status' => 'draft',
        ];
    }
};
PHP,
    ]);

    $modelClass = "App\\{$package}\\Model\\ArticleModel";
    if (!class_exists($modelClass)) {
        require AppTestKit::path($package, 'Model/ArticleModel.php');
    }

    FactoryToolkit::flush();

    $factory = Factory::factoryForModel($modelClass);

    expect($factory)->toBeInstanceOf(FactoryBase::class)
        ->and($factory->modelClass())->toBe($modelClass);

    $article = $modelClass::factory()
        ->state(['status' => 'published'])
        ->make(['title' => 'Hello FactoryBase']);

    expect($article)->toBeInstanceOf($modelClass)
        ->and($article->title)->toBe('Hello FactoryBase')
        ->and($article->status)->toBe('published');

    $many = $modelClass::factory()->count(2)->make();
    expect($many)->toBeInstanceOf(Collection::class)->and($many)->toHaveCount(2);
});
