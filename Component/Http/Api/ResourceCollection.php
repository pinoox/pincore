<?php

namespace Pinoox\Component\Http\Api;

use Countable;
use Illuminate\Pagination\AbstractPaginator;
use IteratorAggregate;
use JsonSerializable;

/**
 * A resource that wraps a collection of resources.
 *
 * Usage:
 *   class PostCollection extends ResourceCollection
 *   {
 *       public $collects = PostResource::class;
 *   }
 *
 * Then in controller:
 *   return $this->resource(new PostCollection($posts));
 */
class ResourceCollection implements JsonSerializable, IteratorAggregate, Countable
{
    /**
     * The resource class this collection wraps.
     */
    protected string $collects;

    /**
     * The collection of resources.
     */
    protected iterable $resources;

    /**
     * Optional pagination data.
     */
    protected ?array $meta = null;

    /**
     * Create a new resource collection instance.
     */
    public function __construct(iterable $resources, ?string $collects = null)
    {
        $this->resources = $resources;
        $this->collects = $collects ?? $this->collects ?? '';
    }

    /**
     * Set pagination metadata.
     */
    public function meta(array $meta): self
    {
        $this->meta = $meta;
        return $this;
    }

    /**
     * Set pagination from a Laravel paginator.
     */
    public static function fromPaginator(AbstractPaginator $paginator, string $collects): self
    {
        $collection = new static($paginator->items(), $collects);
        $collection->meta = [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];

        return $collection;
    }

    /**
     * Transform each item in the collection.
     */
    public function toArray(): array
    {
        if ($this->collects === '') {
            return array_map(
                fn ($item) => $item instanceof ApiResource ? $item->jsonSerialize() : $item,
                iterator_to_array($this->resources)
            );
        }

        $items = [];
        foreach ($this->resources as $resource) {
            $instance = new $this->collects($resource);
            $items[] = $instance->jsonSerialize();
        }

        if ($this->meta !== null) {
            return [
                'data' => $items,
                'meta' => $this->meta,
            ];
        }

        return $items;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function getIterator(): iterable
    {
        return $this->resources;
    }

    public function count(): int
    {
        return is_array($this->resources) ? count($this->resources) : iterator_count($this->resources);
    }
}
