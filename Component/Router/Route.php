<?php

/**
 *      ****  *  *     *  ****  ****  *    *
 *      *  *  *  * *   *  *  *  *  *   *  *
 *      ****  *  *  *  *  *  *  *  *    *
 *      *     *  *   * *  *  *  *  *   *  *
 *      *     *  *    **  ****  ****  *    *
 * @author   Pinoox
 * @link https://www.pinoox.com/
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Component\Router;

use Closure;
use Pinoox\Component\Helpers\Str;
use Pinoox\Component\Package\App;
use Pinoox\Component\Path\Manager\PathManager;
use Pinoox\Component\Router\Parameter\PathCompiler;

class Route
{
    public function __construct(
        private Collection           $collection,
        private string|array         $path,
        private array|string|Closure $action = '',
        private string               $name = '',
        private string|array         $methods = [],
        private array                $defaults = [],
        private array                $filters = [],
        private int                  $priority = 0,
        private string               $prefixName = '',
        public array                 $data = [],
        public array                 $flows = [],
        public array                 $tags = [],

    )
    {
        $this->filters = array_merge($this->collection->filters, $filters);
        // Defaults must exist before path compilation so optional / catch-all
        // parameter defaults from PathCompiler are not overwritten.
        $this->defaults = array_merge($this->collection->defaults, $defaults);
        $this->path = $this->getPath();
        $this->name = $this->buildName($name);
        $this->data = array_merge($this->collection->data, $this->data);
        $this->flows = array_unique(array_merge($this->collection->flows, $flows));
        $this->tags = array_unique(array_merge($this->collection->tags, $tags));
        $this->defaults['_controller'] = $action;
        $actionCollection = $this->collection->action;
        if (!empty($actionCollection)) {
            if (!empty($action))
                $this->defaults['_action_collection'] = $actionCollection;
            else
                $this->defaults['_controller'] = $actionCollection;
        }

        $this->methods = $this->collection->buildMethods($methods);
        $this->defaults['_router'] = $this;
        $this->defaults['_tags'] = $this->tags;
    }

    public function get(): RouteCapsule
    {
        $route = new RouteCapsule($this->path, $this->defaults);
        $route->setMethods($this->methods);
        $route->setRequirements($this->filters);

        return $route;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * build name for route
     *
     * @param string $name
     * @return string
     */
    private function buildName(string $name = ''): string
    {
        return $this->prefixName . $name;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        $basePath = Str::lastDelete($this->collection->prefixPath, '/');

        if ($this->path === '/') {
            return $basePath;
        }

        if ($this->path === '*' || $this->path === '/*') {
            return $this->catchAllPath($basePath, $this->collection->prefixPath);
        }

        // Flattened group fallbacks: "/api/*"
        if (str_ends_with($this->path, '/*')) {
            $prefix = substr($this->path, 0, -2);

            return $this->catchAllPath(
                Str::lastDelete($prefix, '/'),
                $prefix,
            );
        }

        $path = Str::lastDelete($this->path, '/');
        $joined = (new PathManager($basePath))->get($path);

        return $this->compilePath($joined);
    }

    private function compilePath(string $path): string
    {
        // Exact (no placeholders) — highest class of specificity.
        if (!str_contains($path, '{')) {
            $this->priority += 2000;

            return $path;
        }

        $compiled = PathCompiler::compile($path, $this->filters, $this->defaults);
        $this->filters = $compiled['filters'];
        $this->defaults = $compiled['defaults'];
        $this->priority += $compiled['score'];

        return $compiled['path'];
    }

    private function catchAllPath(string $basePath, string $prefixForPriority): string
    {
        $this->filters['parameters'] = '.*';
        $this->priority = -99999 + strlen($prefixForPriority);

        return $basePath . '{parameters}';
    }

    /**
     * @return array
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * @return Collection
     */
    public function getCollection(): Collection
    {
        return $this->collection;
    }

    /**
     * @return array|Closure|string
     */
    public function getAction(): array|string|Closure
    {
        return $this->action;
    }

    /**
     * @param array|Closure|string $action
     */
    public function setAction(array|string|Closure $action): void
    {
        $this->action = $action;
    }

    /**
     * @return array
     */
    public function getDefaults(): array
    {
        return $this->defaults;
    }

    /**
     * @return array|string
     */
    public function getMethods(): array|string
    {
        return $this->methods;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }
}