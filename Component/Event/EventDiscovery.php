<?php

namespace Pinoox\Component\Event;

use Pinoox\Component\AppEvent\AppRegister;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Support\Event\ListensTo;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Auto-register Listener/ classes and optional app.php event maps.
 */
final class EventDiscovery
{
    /**
     * @var array<string, true>
     */
    private static array $registered = [];

    public static function register(string $package, AppRegister $register): void
    {
        if ($package === '' || isset(self::$registered[$package])) {
            return;
        }

        $config = self::config($package);
        if ($config === false) {
            return;
        }

        self::$registered[$package] = true;

        self::loadDirectory($package, 'Event');
        foreach ($config['path'] as $folder) {
            self::loadDirectory($package, $folder);
        }

        if ($config['discover']) {
            foreach ($config['path'] as $folder) {
                self::discoverFolder($package, $folder, $register);
            }
        }

        foreach ($config['listen'] as $event => $listeners) {
            if (!is_string($event) || $event === '') {
                continue;
            }

            foreach (self::normalizeListeners($listeners) as $listener) {
                $register->listen($event, $listener);
            }
        }

        foreach ($config['subscribe'] as $subscriber) {
            if (is_string($subscriber) && $subscriber !== '') {
                $register->subscribe($subscriber);
            }
        }
    }

    public static function resetState(): void
    {
        self::$registered = [];
    }

    /**
     * @return array{discover: bool, path: list<string>, listen: array<string, mixed>, subscribe: list<string>}|false
     */
    public static function config(string $package): array|false
    {
        try {
            $raw = AppEngine::config($package)->get('events');
        } catch (Throwable) {
            $raw = true;
        }

        if ($raw === false) {
            return false;
        }

        if (!is_array($raw)) {
            return [
                'discover' => true,
                'path' => ['Listener'],
                'listen' => [],
                'subscribe' => [],
            ];
        }

        $paths = $raw['path'] ?? ['Listener'];
        if (is_string($paths)) {
            $paths = [$paths];
        }

        $paths = array_values(array_filter(
            is_array($paths) ? $paths : ['Listener'],
            fn ($path) => is_string($path) && $path !== '',
        ));

        if ($paths === []) {
            $paths = ['Listener'];
        }

        $subscribe = $raw['subscribe'] ?? [];
        if (!is_array($subscribe)) {
            $subscribe = [];
        }

        return [
            'discover' => (bool) ($raw['discover'] ?? true),
            'path' => $paths,
            'listen' => is_array($raw['listen'] ?? null) ? $raw['listen'] : [],
            'subscribe' => array_values(array_filter($subscribe, 'is_string')),
        ];
    }

    /**
     * @return list<callable|array|string>
     */
    private static function normalizeListeners(mixed $listeners): array
    {
        if ($listeners === null || $listeners === '') {
            return [];
        }

        if (!is_array($listeners) || self::isListenerTuple($listeners)) {
            return [$listeners];
        }

        return array_values($listeners);
    }

    /**
     * @param array<mixed> $value
     */
    private static function isListenerTuple(array $value): bool
    {
        return array_is_list($value)
            && isset($value[0], $value[1])
            && count($value) === 2
            && is_string($value[0])
            && is_string($value[1]);
    }

    private static function discoverFolder(string $package, string $folder, AppRegister $register): void
    {
        $dir = self::appPath($package, $folder);
        if ($dir === null || !is_dir($dir)) {
            return;
        }

        foreach (self::phpFiles($dir) as $file) {
            $class = self::classFromFile($package, $dir, $file, $folder);
            if ($class === null) {
                continue;
            }

            self::bindClass($class, $register);
        }
    }

    private static function bindClass(string $class, AppRegister $register): void
    {
        if (!class_exists($class)) {
            return;
        }

        try {
            $ref = new ReflectionClass($class);
        } catch (Throwable) {
            return;
        }

        if (!$ref->isInstantiable() || $ref->isAnonymous()) {
            return;
        }

        if ($ref->implementsInterface(EventSubscriberInterface::class)) {
            $register->subscribe($class);

            return;
        }

        $attributed = [];

        foreach ($ref->getAttributes(ListensTo::class) as $attribute) {
            $meta = $attribute->newInstance();
            $method = self::defaultMethodName($ref);
            if ($method === null) {
                continue;
            }

            $register->listen($meta->event, [$class, $method], $meta->priority);
            $attributed[$method] = true;
        }

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor() || $method->isDestructor()) {
                continue;
            }

            foreach ($method->getAttributes(ListensTo::class) as $attribute) {
                $meta = $attribute->newInstance();
                $register->listen($meta->event, [$class, $method->getName()], $meta->priority);
                $attributed[$method->getName()] = true;
            }
        }

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor() || $method->isDestructor()) {
                continue;
            }

            if (isset($attributed[$method->getName()]) || !self::isHandlerMethod($method)) {
                continue;
            }

            foreach (EventListener::eventTypesOf($method) as $event) {
                $register->listen($event, [$class, $method->getName()]);
            }
        }
    }

    private static function isHandlerMethod(ReflectionMethod $method): bool
    {
        $name = $method->getName();

        return $name === '__invoke' || str_starts_with($name, 'handle');
    }

    private static function defaultMethodName(ReflectionClass $ref): ?string
    {
        if ($ref->hasMethod('handle')) {
            $method = $ref->getMethod('handle');
            if ($method->isPublic() && !$method->isStatic()) {
                return 'handle';
            }
        }

        if ($ref->hasMethod('__invoke')) {
            $method = $ref->getMethod('__invoke');
            if ($method->isPublic() && !$method->isStatic()) {
                return '__invoke';
            }
        }

        return null;
    }

    private static function loadDirectory(string $package, string $folder): void
    {
        $dir = self::appPath($package, $folder);
        if ($dir === null || !is_dir($dir)) {
            return;
        }

        foreach (self::phpFiles($dir) as $file) {
            try {
                require_once $file;
            } catch (Throwable) {
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $dir): array
    {
        $files = [];

        try {
            $finder = (new Finder())->files()->in($dir)->name('*.php')->sortByName();
            foreach ($finder as $file) {
                $path = $file->getRealPath();
                if (is_string($path) && $path !== '') {
                    $files[] = str_replace('\\', '/', $path);
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $files;
    }

    private static function classFromFile(string $package, string $dir, string $file, string $folder): ?string
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $file = str_replace('\\', '/', $file);
        if (!str_starts_with($file, $dir . '/')) {
            return null;
        }

        $relative = substr($file, strlen($dir) + 1);
        $relative = preg_replace('/\.php$/i', '', $relative) ?? $relative;
        $relative = str_replace('/', '\\', $relative);

        $folderNs = str_replace(['/', '\\'], '\\', trim($folder, '/\\'));

        return 'App\\' . $package . '\\' . $folderNs . '\\' . $relative;
    }

    private static function appPath(string $package, string $folder): ?string
    {
        try {
            $path = AppEngine::path($package, $folder);
        } catch (Throwable) {
            return null;
        }

        return is_string($path) && $path !== '' ? str_replace('\\', '/', $path) : null;
    }
}
