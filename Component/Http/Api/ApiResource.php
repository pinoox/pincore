<?php

namespace Pinoox\Component\Http\Api;

use Countable;
use Illuminate\Pagination\AbstractPaginator;
use IteratorAggregate;
use JsonSerializable;
use Pinoox\Component\Http\Request;

/**
 * Features:
 * - Whenable: conditionally include fields
 * - ResourceCollection: wraps collections with pagination
 * - Transformable: automatic toArray for models
 */
abstract class ApiResource implements JsonSerializable
{
    protected const WRAPPED = false;

    protected ?Request $request = null;

    /**
     * Additional top-level data (from ->additional()).
     */
    protected array $additional = [];

    public function __construct(protected mixed $resource)
    {
        $this->request = $this->resolveRequest();
    }

    /**
     * Proxy property access to the underlying resource.
     */
    public function __get(string $name): mixed
    {
        return $this->resource->{$name} ?? null;
    }

    /**
     * Proxy method calls to the underlying resource.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (is_object($this->resource) && method_exists($this->resource, $method)) {
            return $this->resource->{$method}(...$parameters);
        }

        throw new \BadMethodCallException(sprintf('Method %s does not exist on the resource or its underlying model.', $method));
    }

    /**
     * Resolve the current HTTP request.
     */
    private function resolveRequest(): ?Request
    {
        return self::resolveCurrentRequest();
    }

    /**
     * Transform the resource into an array.
     * Override this in your resource class — Laravel style.
     *
     * @return array
     */
    public function toArray(Request $request)
    {
        return [];
    }

    /**
     * Create a new resource instance (Laravel-style factory).
     *
     * Usage:
     *   $resource = PostResource::make($post);
     *   $resource = PostResource::make($post, CustomPostResource::class);
     */
    public static function make(mixed $resource, ?string $resourceClass = null): static
    {
        $class = $resourceClass ?? static::class;
        return new $class($resource);
    }

    public function jsonSerialize(): array
    {
        return $this->resolve();
    }

    /**
     * Resolve the resource to an array.
     * Transforms and passes the current Request to toArray().
     */
    public function resolve(): array
    {
        $request = $this->request ?? self::resolveCurrentRequest();

        $data = $this->toArray($request ?: Request::create('', 'GET'));

        if (is_array($data)) {
            $data = array_merge($data, $this->with($request));
        }

        return $data;
    }

    /**
     * Get additional top-level data.
     * Override in resource to add meta.
     */
    public function with(?Request $request = null): array
    {
        return $this->additional;
    }

    /**
     * Add additional top-level data to the resource.
     */
    public function additional(array $data): static
    {
        $this->additional = array_merge($this->additional, $data);
        return $this;
    }

    /**
     * Transform a collection of resources (Laravel-style).
     *
     * Usage:
     *   UserResource::collection(User::all())           // inferred from static
     *   UserResource::collection($items, UserResource::class)
     *   PostResource::collection($paginator, PostResource::class)
     */
    public static function collection(iterable $items, ?string $resourceClass = null): array
    {
        $resourceClass = $resourceClass ?? static::class;
        $resources = [];
        $request = self::resolveCurrentRequest();

        foreach ($items as $item) {
            $instance = new $resourceClass($item);
            if ($request !== null) {
                $instance->setRequest($request);
            }
            $resources[] = $instance->jsonSerialize();
        }

        return $resources;
    }

    /**
     * Set the current request on the resource.
     */
    public function setRequest(?Request $request): static
    {
        $this->request = $request;
        return $this;
    }

    /**
     * @return Request|null
     */
    private static function resolveCurrentRequest(): ?Request
    {
        try {
            if (method_exists(Request::class, 'take')) {
                return Request::take();
            }
            if (method_exists(Request::class, 'createFromGlobals')) {
                return Request::createFromGlobals();
            }
        } catch (\Throwable) {
        }
        return null;
    }

    /**
     * Transform a paginated collection.
     *
     * @param AbstractPaginator $paginator
     * @param class-string<static> $resourceClass
     * @return array{data: array, meta: array}
     */
    public static function paginator(AbstractPaginator $paginator, string $resourceClass): array
    {
        return [
            'data' => static::collection($paginator->items(), $resourceClass),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * Conditionally include a field when the condition is true.
     * Accepts scalar values, arrays, or callables.
     */
    protected function when(bool $condition, mixed $value = true, mixed $default = null): mixed
    {
        return $condition
            ? (is_callable($value) ? $value() : $value)
            : $default;
    }

    /**
     * Include an attribute only if it exists on the underlying resource.
     */
    protected function whenHas(string $key, mixed $value = null): mixed
    {
        if (is_object($this->resource) && isset($this->resource->{$key})) {
            return $value ?? $this->resource->{$key};
        }

        if (is_array($this->resource) && array_key_exists($key, $this->resource)) {
            return $value ?? $this->resource[$key];
        }

        return null;
    }

    /**
     * Include a value only when it is not null.
     */
    protected function whenNotNull(mixed $value): mixed
    {
        return $value !== null ? $value : null;
    }

    /**
     * Include a relation only if it has been loaded on the model.
     */
    protected function whenLoaded(string $relation, mixed $value = null, mixed $default = null): mixed
    {
        if (!is_object($this->resource) || !method_exists($this->resource, 'relationLoaded')) {
            return $default;
        }

        if (!$this->resource->relationLoaded($relation)) {
            return $default;
        }

        return $value ?? $this->resource->{$relation};
    }

    /**
     * Include a relationship count only if it has been loaded.
     */
    protected function whenCounted(string $relation, ?string $key = null): mixed
    {
        $key = $key ?? $relation . '_count';

        if (is_object($this->resource) && property_exists($this->resource, $key)) {
            return (int) $this->resource->{$key};
        }

        return null;
    }

    /**
     * Merge conditional attributes.
     */
    protected function mergeWhen(bool $condition, array $value): array|callable
    {
        if (is_callable($value)) {
            return $condition ? $value() : [];
        }

        return $condition ? $value : [];
    }

    /**
     * Include a relation if it has been loaded.
     * Accepts a relation name and optional transformation callback.
     */
    protected function includeRelation(string $relation, ?callable $callback = null): mixed
    {
        return $this->whenLoaded($relation, $this->resource->{$relation}, function () use ($callback) {
            return null;
        });
    }

    /**
     * Merge arrays, removing null values — useful with when() calls.
     */
    protected function merge(array ...$arrays): array
    {
        return array_filter(
            array_merge(...$arrays),
            fn ($v) => $v !== null
        );
    }

    /**
     * Filter null values from an array (Laravel-style resource filter).
     */
    protected function filter(array $data): array
    {
        return array_filter($data, fn ($v) => $v !== null);
    }

    /**
     * Transform a date to ISO 8601 string.
     */
    protected function date(?\DateTimeInterface $date): ?string
    {
        return $date?->toIso8601String();
    }

    /**
     * Get the resource key (for wrapped collections).
     */
    protected function getResourceKey(): string
    {
        if ($this->resource instanceof Countable) {
            return 'data';
        }

        return static::WRAPPED ? $this->resource->getResource() : 'data';
    }
}
