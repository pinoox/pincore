<?php

namespace Pinoox\Component\Router;

class RouteManifest
{
    /**
     * @param array<string, mixed> $manifest
     */
    public static function apply(Router $router, array $manifest): void
    {
        $routes = self::routes($manifest);
        if ($routes === []) {
            return;
        }

        $globalFlow = self::list($manifest['flow'] ?? []);
        $globalTags = self::list($manifest['tags'] ?? []);

        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }

            $entry = self::normalizeEntry($route, $globalFlow, $globalTags);

            $builder = $router->route($entry['path'], $entry['action'])
                ->methods($entry['methods'])
                ->name($entry['name'])
                ->flows($entry['flow'])
                ->defaults($entry['defaults'])
                ->filters($entry['filters'])
                ->data($entry['data'])
                ->tags($entry['tags']);

            if ($entry['priority'] !== null) {
                $builder->priority($entry['priority']);
            }

            $builder->register();
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public static function normalizeManifest(array $manifest): array
    {
        $routes = self::routes($manifest);
        $normalizedRoutes = [];

        $globalFlow = self::list($manifest['flow'] ?? []);
        $globalTags = self::list($manifest['tags'] ?? []);

        foreach ($routes as $route) {
            if (!is_array($route)) {
                continue;
            }

            $normalizedRoutes[] = self::normalizeEntry($route, $globalFlow, $globalTags, forApi: true);
        }

        if (self::isManifest($manifest)) {
            $manifest['routes'] = $normalizedRoutes;

            return $manifest;
        }

        return $normalizedRoutes;
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function isManifest(array $value): bool
    {
        return isset($value['routes']) && is_array($value['routes']);
    }

    /**
     * @param array<int|string, mixed> $value
     */
    public static function isRouteList(array $value): bool
    {
        if ($value === [] || !array_is_list($value)) {
            return false;
        }

        $first = $value[0] ?? null;

        return is_array($first)
            && (
                isset($first['path'])
                || isset($first['uri'])
                || isset($first['method'])
                || isset($first['methods'])
                || self::isRouteGroup($first)
            );
    }

    /**
     * @param array<string, mixed> $manifest
     * @return list<array<string, mixed>>
     */
    public static function routes(array $manifest): array
    {
        if (self::isManifest($manifest)) {
            return self::expandRoutes(array_values(array_filter($manifest['routes'], is_array(...))));
        }

        if (self::isRouteList($manifest)) {
            return self::expandRoutes($manifest);
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $routes
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    public static function expandRoutes(array $routes, array $context = []): array
    {
        $expanded = [];

        foreach ($routes as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (self::isRouteGroup($entry)) {
                $expanded = array_merge(
                    $expanded,
                    self::expandRoutes($entry['routes'], self::mergeGroupContext($context, $entry)),
                );
                continue;
            }

            $expanded[] = self::applyGroupContext($entry, $context);
        }

        return $expanded;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public static function isRouteGroup(array $entry): bool
    {
        if (!isset($entry['routes']) || !is_array($entry['routes'])) {
            return false;
        }

        return !array_key_exists('action', $entry);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    public static function normalizeEntry(array $entry, array $globalFlow = [], array $globalTags = [], bool $forApi = false): array
    {
        $path = trim((string) ($entry['path'] ?? $entry['uri'] ?? '/'));
        if ($path === '') {
            $path = '/';
        }

        $methods = $entry['methods'] ?? $entry['method'] ?? 'GET';
        $methods = array_values(array_unique(array_map(
            static fn(mixed $method): string => strtoupper((string) $method),
            is_array($methods) ? $methods : [$methods],
        )));

        $flow = array_values(array_unique(array_merge(
            $globalFlow,
            self::list($entry['flow'] ?? $entry['flows'] ?? []),
        )));

        $permission = self::routePermission($entry);
        $flow = self::withPermissionFlow($flow, $permission);

        $tags = array_values(array_unique(array_merge(
            $globalTags,
            self::list($entry['tags'] ?? []),
        )));

        $normalized = [
            'method' => $methods[0] ?? 'GET',
            'methods' => $methods,
            'path' => $path,
            'uri' => $path,
            'action' => $entry['action'] ?? null,
            'name' => (string) ($entry['name'] ?? ''),
            'flow' => $flow,
            'defaults' => is_array($entry['defaults'] ?? null) ? $entry['defaults'] : [],
            'filters' => is_array($entry['filters'] ?? null) ? $entry['filters'] : [],
            'data' => is_array($entry['data'] ?? null) ? $entry['data'] : [],
            'tags' => $tags,
            'priority' => isset($entry['priority']) ? (int) $entry['priority'] : null,
        ];

        if ($permission !== null) {
            $normalized['permission'] = $permission;
            $normalized['data']['permission'] = $permission;
        }

        if (!$forApi) {
            return $normalized;
        }

        foreach ([
            'permission',
            'auth',
            'rate_limit',
            'rateLimit',
            'request',
            'resource',
            'description',
            'summary',
            'tag',
            'deprecated',
            'params',
            'body',
            'body_description',
            'body_example',
            'response',
            'responses',
        ] as $field) {
            if (array_key_exists($field, $entry)) {
                $normalized[$field] = $entry[$field];
            }
        }

        if (isset($normalized['rateLimit']) && !isset($normalized['rate_limit'])) {
            $normalized['rate_limit'] = $normalized['rateLimit'];
            unset($normalized['rateLimit']);
        }

        return $normalized;
    }

    /**
     * @param list<mixed> $flow
     * @return list<mixed>
     */
    public static function withPermissionFlow(array $flow, ?string $permission): array
    {
        if ($permission === null || $permission === '') {
            return $flow;
        }

        if (!in_array('permission', $flow, true)) {
            $flow[] = 'permission';
        }

        return $flow;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function routePermission(array $entry): ?string
    {
        if (!empty($entry['permission'])) {
            return (string) $entry['permission'];
        }

        if (!empty($entry['data']['permission'])) {
            return (string) $entry['data']['permission'];
        }

        return null;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return is_array($value) ? array_values($value) : [$value];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    public static function mergeGroupContext(array $context, array $group): array
    {
        $prefix = self::groupPathPrefix($group, $context);
        $namePrefix = self::groupNamePrefix($group, $context);
        $controller = $group['controller'] ?? $context['controller'] ?? null;

        return [
            'prefix' => $prefix,
            'name_prefix' => $namePrefix,
            'controller' => is_string($controller) ? $controller : null,
            'flow' => array_values(array_unique(array_merge(
                self::list($context['flow'] ?? []),
                self::list($group['flow'] ?? $group['flows'] ?? $group['middleware'] ?? []),
            ))),
            'tags' => array_values(array_unique(array_merge(
                self::list($context['tags'] ?? []),
                self::list($group['tags'] ?? []),
            ))),
            'defaults' => array_merge(
                is_array($context['defaults'] ?? null) ? $context['defaults'] : [],
                is_array($group['defaults'] ?? null) ? $group['defaults'] : [],
            ),
            'filters' => array_merge(
                is_array($context['filters'] ?? null) ? $context['filters'] : [],
                is_array($group['filters'] ?? null) ? $group['filters'] : [],
            ),
            'data' => array_merge(
                is_array($context['data'] ?? null) ? $context['data'] : [],
                is_array($group['data'] ?? null) ? $group['data'] : [],
            ),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function applyGroupContext(array $entry, array $context): array
    {
        $path = (string) ($entry['path'] ?? $entry['uri'] ?? '/');
        $entry['uri'] = self::joinPath((string) ($context['prefix'] ?? ''), $path);
        unset($entry['path']);

        $name = (string) ($entry['name'] ?? '');
        $namePrefix = (string) ($context['name_prefix'] ?? '');
        if ($name !== '' || $namePrefix !== '') {
            $entry['name'] = self::joinName($namePrefix, $name);
        }

        $controller = $context['controller'] ?? null;
        if ($controller !== null && is_string($entry['action'] ?? null)) {
            $entry['action'] = [$controller, (string) $entry['action']];
        }

        $entry['flow'] = array_values(array_unique(array_merge(
            self::list($context['flow'] ?? []),
            self::list($entry['flow'] ?? $entry['flows'] ?? []),
        )));

        $entry['tags'] = array_values(array_unique(array_merge(
            self::list($context['tags'] ?? []),
            self::list($entry['tags'] ?? []),
        )));

        if (!empty($context['defaults']) && is_array($context['defaults'])) {
            $entry['defaults'] = array_merge(
                $context['defaults'],
                is_array($entry['defaults'] ?? null) ? $entry['defaults'] : [],
            );
        }

        if (!empty($context['filters']) && is_array($context['filters'])) {
            $entry['filters'] = array_merge(
                $context['filters'],
                is_array($entry['filters'] ?? null) ? $entry['filters'] : [],
            );
        }

        if (!empty($context['data']) && is_array($context['data'])) {
            $entry['data'] = array_merge(
                $context['data'],
                is_array($entry['data'] ?? null) ? $entry['data'] : [],
            );
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $group
     * @param array<string, mixed> $context
     */
    private static function groupPathPrefix(array $group, array $context): string
    {
        $prefix = (string) ($group['prefix'] ?? '');

        return self::joinPath((string) ($context['prefix'] ?? ''), $prefix);
    }

    /**
     * @param array<string, mixed> $group
     * @param array<string, mixed> $context
     */
    private static function groupNamePrefix(array $group, array $context): string
    {
        $prefix = (string) ($group['as'] ?? $group['name'] ?? $group['name_prefix'] ?? '');

        return (string) ($context['name_prefix'] ?? '') . $prefix;
    }

    private static function joinPath(string $prefix, string $path): string
    {
        $prefix = trim($prefix);
        $path = trim($path);

        if ($path === '' || $path === '/') {
            if ($prefix === '' || $prefix === '/') {
                return '/';
            }

            return '/' . trim($prefix, '/');
        }

        if ($prefix === '' || $prefix === '/') {
            return '/' . ltrim($path, '/');
        }

        return '/' . trim(trim($prefix, '/') . '/' . ltrim($path, '/'), '/');
    }

    private static function joinName(string $prefix, string $name): string
    {
        if ($prefix === '') {
            return $name;
        }

        if ($name === '') {
            return rtrim($prefix, '.');
        }

        return rtrim($prefix, '.') . '.' . ltrim($name, '.');
    }
}

