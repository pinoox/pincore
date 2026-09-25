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

use Pinoox\Component\Http\Request;
use Pinoox\Component\Package\SubApp;

class SubAppRouteBuilder
{
    private string $name = '';
    private array $methods = RouteMethod::METHODS;
    private array $flows = [];
    private array $data = [];
    private array $tags = [];
    private array $options = [];
    private ?int $priority = 2000;
    private bool $registered = false;

    public function __construct(
        private readonly Router|RouteRegister $target,
        private readonly string $path,
        private readonly string $package,
        array $options = [],
    ) {
        $this->options = $options;
    }

    public function config(array $config): self
    {
        $this->options['config'] = array_replace_recursive($this->options['config'] ?? [], $config);

        return $this;
    }

    /**
     * Specify selective route file(s) for the sub-app mount instead of loading all default routes.
     *
     * @param string|list<string> $routeFiles
     */
    public function routes(string|array $routeFiles): self
    {
        $this->options['routes'] = is_array($routeFiles) ? $routeFiles : [$routeFiles];

        return $this;
    }

    /**
     * Filter mounted sub-app routes to only include routes having specified tag(s) or context(s).
     *
     * @param array<string>|string $tagsOrContexts
     */
    public function only(array|string $tagsOrContexts): self
    {
        $tags = is_array($tagsOrContexts) ? $tagsOrContexts : [$tagsOrContexts];
        $this->options['only_tags'] = array_values(array_filter(array_map('trim', $tags)));

        return $this;
    }

    /**
     * Pass contextual data or lazy callbacks to the sub-app layer.
     *
     * @param array<string, mixed> $context
     */
    public function context(array $context): self
    {
        $this->options['context'] = array_merge($this->options['context'] ?? [], $context);

        return $this;
    }

    public function shareAuth(bool $share = true): self
    {
        $this->options['share_auth'] = $share;

        return $this;
    }

    public function flows(array $flows): self
    {
        $this->flows = array_unique(array_merge($this->flows, $flows));

        return $this;
    }

    public function priority(?int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function methods(array|string $methods): self
    {
        $this->methods = RouteMethod::normalize($methods);

        return $this;
    }

    public function data(array $data): self
    {
        $this->data = array_merge($this->data, $data);

        return $this;
    }

    public function tags(array $tags): self
    {
        $this->tags = array_unique(array_merge($this->tags, $tags));

        return $this;
    }

    public function options(array $options): self
    {
        $this->options = array_replace_recursive($this->options, $options);

        return $this;
    }

    /**
     * Set a custom filesystem path for the sub-app.
     */
    public function path(string $path): self
    {
        $this->options['path'] = $path;

        return $this;
    }

    /**
     * Alias of path() to explicitly set the sub-app directory path.
     */
    public function appPath(string $path): self
    {
        return $this->path($path);
    }

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        $package = SubApp::resolvePackage($this->package, $this->options);
        $mountPath = $this->path;
        $options = $this->options;

        $action = static function (Request $request) use ($package, $mountPath, $options) {
            return SubApp::run($package, $mountPath, $request, $options);
        };

        $normalizedPath = trim($this->path, '/');
        $routePattern = $normalizedPath === '' ? '{subPath*?}' : $normalizedPath . '/{subPath*?}';

        $data = array_merge($this->data, [
            'sub_app' => $package,
            'mount_path' => $this->path,
        ]);

        if ($this->target instanceof Router) {
            $builder = $this->target->route($routePattern, $action)
                ->methods($this->methods)
                ->flows($this->flows)
                ->data($data)
                ->tags($this->tags);

            if ($this->priority !== null) {
                $builder->priority($this->priority);
            }

            if ($this->name !== '') {
                $builder->name($this->name);
            }

            $builder->register();
        } else {
            $builder = $this->target->any($routePattern, $action)
                ->methods($this->methods)
                ->flows($this->flows)
                ->data($data)
                ->tags($this->tags);

            if ($this->priority !== null) {
                $builder->priority($this->priority);
            }

            if ($this->name !== '') {
                $builder->name($this->name);
            }
        }
    }

    public function __destruct()
    {
        if (!$this->registered) {
            $this->register();
        }
    }
}
