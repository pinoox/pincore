<?php

namespace Pinoox\Component\Database\Factories;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model as EloquentModel;

abstract class Factory
{
    protected ?string $model = null;

    private ?int $count = null;

    /** @var list<array|callable> */
    private array $states = [];

    /** @var list<array|callable> */
    private array $sequence = [];

    /** @var list<callable> */
    private array $afterMaking = [];

    /** @var list<callable> */
    private array $afterCreating = [];

    public static function new(int|array|callable|null $count = null, array|callable $state = []): static
    {
        $factory = new static();

        if (is_int($count)) {
            $factory = $factory->count($count);
        } elseif ($count !== null) {
            $factory = $factory->state($count);
        }

        if ($state !== []) {
            $factory = $factory->state($state);
        }

        return $factory;
    }

    public static function factoryForModel(string $modelClass): self
    {
        foreach (self::factoryClassCandidates($modelClass) as $factoryClass) {
            if (class_exists($factoryClass) && is_subclass_of($factoryClass, self::class)) {
                $factory = new $factoryClass();
                $factory->model = $modelClass;

                return $factory;
            }
        }

        throw new \RuntimeException(sprintf(
            'No factory found for model [%s]. Create one with: php pinoox factory:create %s {package}',
            $modelClass,
            self::modelBasename($modelClass) . 'Factory',
        ));
    }

    /**
     * Define the default attributes for the model.
     */
    abstract public function definition(): array;

    public function count(?int $count): static
    {
        $factory = clone $this;
        $factory->count = $count;

        return $factory;
    }

    public function state(array|callable $state): static
    {
        $factory = clone $this;
        $factory->states[] = $state;

        return $factory;
    }

    public function sequence(array|callable ...$sequence): static
    {
        $factory = clone $this;
        $factory->sequence = array_values($sequence);

        return $factory;
    }

    public function afterMaking(callable $callback): static
    {
        $factory = clone $this;
        $factory->afterMaking[] = $callback;

        return $factory;
    }

    public function afterCreating(callable $callback): static
    {
        $factory = clone $this;
        $factory->afterCreating[] = $callback;

        return $factory;
    }

    public function raw(array $attributes = []): array
    {
        if ($this->count === null) {
            return $this->attributesFor(0, $attributes);
        }

        $rows = [];
        for ($i = 0; $i < $this->count; $i++) {
            $rows[] = $this->attributesFor($i, $attributes);
        }

        return $rows;
    }

    public function make(array $attributes = []): EloquentModel|Collection
    {
        if ($this->count === null) {
            $model = $this->makeInstance($this->attributesFor(0, $attributes));
            $this->callAfterMaking($model, 0);

            return $model;
        }

        $models = new Collection();
        for ($i = 0; $i < $this->count; $i++) {
            $model = $this->makeInstance($this->attributesFor($i, $attributes));
            $this->callAfterMaking($model, $i);
            $models->push($model);
        }

        return $models;
    }

    public function create(array $attributes = []): EloquentModel|Collection
    {
        $models = $this->make($attributes);
        $items = $models instanceof Collection ? $models : new Collection([$models]);

        foreach ($items as $index => $model) {
            $model->save();
            $this->callAfterCreating($model, $index);
        }

        return $models;
    }

    protected function modelName(): string
    {
        if ($this->model !== null && $this->model !== '') {
            return $this->model;
        }

        foreach ($this->modelClassCandidates() as $modelClass) {
            if (class_exists($modelClass)) {
                return $this->model = $modelClass;
            }
        }

        throw new \RuntimeException(sprintf('Unable to determine model class for factory [%s].', static::class));
    }

    protected function faker(): object
    {
        if (!class_exists(\Faker\Factory::class)) {
            throw new \RuntimeException('fakerphp/faker is not installed. Run: composer require --dev fakerphp/faker');
        }

        return \Faker\Factory::create();
    }

    private function makeInstance(array $attributes): EloquentModel
    {
        $modelClass = $this->modelName();
        $model = new $modelClass();

        if (!$model instanceof EloquentModel) {
            throw new \RuntimeException(sprintf('Factory model [%s] must extend Illuminate Eloquent Model.', $modelClass));
        }

        return $model->forceFill($attributes);
    }

    private function attributesFor(int $index, array $overrides = []): array
    {
        $attributes = $this->definition();

        foreach ($this->states as $state) {
            $attributes = array_merge($attributes, $this->evaluateState($state, $attributes, $index));
        }

        if ($this->sequence !== []) {
            $state = $this->sequence[$index % count($this->sequence)];
            $attributes = array_merge($attributes, $this->evaluateState($state, $attributes, $index));
        }

        $attributes = array_merge($attributes, $overrides);

        foreach ($attributes as $key => $value) {
            if ($value instanceof Closure) {
                $attributes[$key] = $value($attributes, $index);
            }
        }

        return $attributes;
    }

    private function evaluateState(array|callable $state, array $attributes, int $index): array
    {
        $state = is_callable($state) ? $state($attributes, $index) : $state;

        if (!is_array($state)) {
            throw new \RuntimeException('Factory state callbacks must return an array.');
        }

        return $state;
    }

    private function callAfterMaking(EloquentModel $model, int $index): void
    {
        foreach ($this->afterMaking as $callback) {
            $callback($model, $index);
        }
    }

    private function callAfterCreating(EloquentModel $model, int $index): void
    {
        foreach ($this->afterCreating as $callback) {
            $callback($model, $index);
        }
    }

    /**
     * @return list<class-string>
     */
    private static function factoryClassCandidates(string $modelClass): array
    {
        $modelBase = self::modelBasename($modelClass);
        $withModelSuffix = self::classBasename($modelClass);

        if (preg_match('/^App\\\\([^\\\\]+)\\\\Model\\\\(.+)$/', $modelClass, $matches)) {
            $package = $matches[1];
            $sub = trim(dirname(str_replace('\\', '/', $matches[2])), '.');
            $subNamespace = $sub !== '' ? '\\' . str_replace('/', '\\', $sub) : '';

            return array_values(array_unique([
                'App\\' . $package . '\\database\\factories' . $subNamespace . '\\' . $modelBase . 'Factory',
                'App\\' . $package . '\\database\\factories' . $subNamespace . '\\' . $withModelSuffix . 'Factory',
            ]));
        }

        return [
            preg_replace('/\\\\Model\\\\/', '\\Database\\Factories\\', $modelClass) . 'Factory',
            $modelClass . 'Factory',
        ];
    }

    /**
     * @return list<class-string>
     */
    private function modelClassCandidates(): array
    {
        $factoryClass = static::class;
        $short = self::classBasename($factoryClass);
        $base = str_ends_with($short, 'Factory') ? substr($short, 0, -7) : $short;

        if (preg_match('/^App\\\\([^\\\\]+)\\\\database\\\\factories(?:\\\\(.+))?\\\\[^\\\\]+$/', $factoryClass, $matches)) {
            $package = $matches[1];
            $sub = isset($matches[2]) ? '\\' . $matches[2] : '';

            return [
                'App\\' . $package . '\\Model' . $sub . '\\' . $base . 'Model',
                'App\\' . $package . '\\Model' . $sub . '\\' . $base,
            ];
        }

        return [];
    }

    private static function modelBasename(string $modelClass): string
    {
        $base = self::classBasename($modelClass);

        return str_ends_with($base, 'Model') && strlen($base) > 5 ? substr($base, 0, -5) : $base;
    }

    private static function classBasename(string $class): string
    {
        $parts = explode('\\', $class);

        return end($parts) ?: $class;
    }
}
