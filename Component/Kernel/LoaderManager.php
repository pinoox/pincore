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

namespace Pinoox\Component\Kernel;

use Composer\Autoload\ClassLoader;

/**
 * Class LoaderManager
 * @author   Vladimir Marchevsky
 * @link https://github.com/vladimmi/construct-static
 */
class LoaderManager

{
    const method = '__registerLifecycle';
    const classes = [
        'Portal\\',
        'Pinoox\Component\Kernel\BootInterface'
    ];

    /**
     * Wrapped Composer object
     *
     * @var ClassLoader
     */
    private ClassLoader $loader;

    /**
     * Parameters to pass into constructors
     *
     * @var array
     */
    private array $params = [];

    /**
     * Parameters to pass into constructors of specified class
     *
     * @var array
     */
    private array $classParams = [];

    /**
     * Call static constructor for class if exists
     *
     * @param string $className
     * @throws \ReflectionException
     */
    private function callConstruct(string $className): void
    {
        $classes = array_filter(self::classes, function ($class) use ($className) {
            return ($className !== $class) && (str_contains($className, $class) || is_subclass_of($className, $class));
        });

        if (empty($classes))
            return;

        if (method_exists($className, self::method)) {
            call_user_func([$className, self::method]);
        }
    }

    /**
     * @param ClassLoader $loader Composer loader object
     * @param array $params Additional parameters to pass into constructors, like DI container, etc
     */
    public function __construct(ClassLoader $loader, array $params = [])
    {
        $this->loader = $loader;
        $this->params = $params;

        //unregister composer
        $loaders = spl_autoload_functions();
        foreach ($loaders as $l) {
            // we need to replace only composer
            if (is_array($l) && $l[0] instanceof ClassLoader) {
                spl_autoload_unregister($l);
            }
        }

        //register wrapper
        spl_autoload_register([$this, 'loadClass'], true, true);
    }

    /** @var array<string, bool> */
    private array $attemptedAppAutoloads = [];

    /**
     * Loads the given class or interface and invokes static constructor on it
     *
     * @param string $className The name of the class
     * @return bool|null True if loaded, null otherwise
     * @throws \ReflectionException
     */
    public function loadClass($className): ?bool
    {
        if (str_starts_with($className, 'App\\')) {
            $parts = explode('\\', $className);
            if (count($parts) >= 2 && $parts[1] !== '') {
                $packageName = $parts[1];
                if ($this->resolveAppAutoload($packageName)) {
                    // In case Composer already recorded this class as missing before registration
                    try {
                        $unsetMissing = \Closure::bind(function ($c) {
                            unset($this->missingClasses[$c]);
                        }, $this->loader, \Composer\Autoload\ClassLoader::class);
                        $unsetMissing($className);
                    } catch (\Throwable) {
                    }
                }
            }
        }

        $result = $this->loader->loadClass($className);
        if ($result === true) {
            //class loaded successfully
            $this->callConstruct($className);
            return true;
        }

        return null;
    }

    private function resolveAppAutoload(string $packageName): bool
    {
        if (isset($this->attemptedAppAutoloads[$packageName])) {
            return $this->attemptedAppAutoloads[$packageName];
        }

        $path = null;

        // 1. AppEngine path
        if (class_exists(\Pinoox\Portal\App\AppEngine::class)) {
            try {
                if (\Pinoox\Portal\App\AppEngine::exists($packageName)) {
                    $candidate = \Pinoox\Portal\App\AppEngine::path($packageName);
                    if (!empty($candidate) && is_dir($candidate)) {
                        $path = $candidate;
                    }
                }
            } catch (\Throwable) {
            }
        }

        // 2. AppDevRegistry path (running or registered dev apps from ~/.pinoox/dev_apps.json)
        if ($path === null && class_exists(\Pinoox\Component\Server\AppDevRegistry::class)) {
            $candidate = \Pinoox\Component\Server\AppDevRegistry::path($packageName);
            if (!empty($candidate) && is_dir($candidate)) {
                $path = $candidate;
            }
        }

        // 3. SubApp resolution (sub_apps config, sibling dirs, environment, etc.)
        if ($path === null && class_exists(\Pinoox\Component\Package\SubApp::class)) {
            try {
                if (\Pinoox\Component\Package\SubApp::exists($packageName)) {
                    $candidate = \Pinoox\Component\Package\SubApp::path($packageName);
                    if (!empty($candidate) && is_dir($candidate)) {
                        $path = $candidate;
                    }
                }
            } catch (\Throwable) {
            }
        }

        if ($path !== null) {
            $normalized = rtrim(str_replace('\\', '/', $path), '/');
            $this->loader->addPsr4('App\\' . $packageName . '\\', $normalized, true);

            if (class_exists(\Pinoox\Portal\App\AppEngine::class)) {
                try {
                    \Pinoox\Portal\App\AppEngine::add($packageName, $normalized);
                } catch (\Throwable) {
                }
            }

            return $this->attemptedAppAutoloads[$packageName] = true;
        }

        return $this->attemptedAppAutoloads[$packageName] = false;
    }

    /**
     * Set parameters to pass into specified class instead of default ones
     *
     * @param string $className
     * @param array $params
     */
    public function setClassParameters($className, $params): void
    {
        $this->classParams[$className] = $params;
    }

    /**
     * Call static constructors on previously loaded classes
     * @throws \ReflectionException
     */
    public function processLoadedClasses(): void
    {
        $classes = get_declared_classes();
        foreach ($classes as $className) {
            $this->callConstruct($className);
        }
    }
}

