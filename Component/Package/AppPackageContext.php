<?php

namespace Pinoox\Component\Package;

use Pinoox\Component\Kernel\Exception;
use Pinoox\Component\Package\Engine\AppEngine;
use Pinoox\Component\Router\Action\ActionRegistry;
use Pinoox\Portal\App\App;
use Pinoox\Portal\Config;
use Pinoox\Portal\Lang;

/**
 * Runtime access to another app's resources when it is installed.
 */
final class AppPackageContext
{
    public function __construct(
        private readonly string $package,
        private readonly AppEngine $engine,
    ) {
    }

    public function package(): string
    {
        return $this->package;
    }

    public function exists(): bool
    {
        return $this->engine->exists($this->package);
    }

    public function stable(): bool
    {
        return $this->engine->stable($this->package);
    }

    public function versionCode(): ?int
    {
        if (!$this->exists()) {
            return null;
        }

        $appFile = $this->engine->path($this->package, 'app.php');
        if (!is_file($appFile)) {
            return null;
        }

        $data = include $appFile;

        return is_array($data) ? (int) ($data['version-code'] ?? 0) : null;
    }

    public function versionName(): ?string
    {
        if (!$this->exists()) {
            return null;
        }

        $appFile = $this->engine->path($this->package, 'app.php');
        if (!is_file($appFile)) {
            return null;
        }

        $data = include $appFile;

        return is_array($data) ? (string) ($data['version-name'] ?? '') : null;
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        $this->assertExists();

        return App::meeting($this->package, static function () use ($key, $default) {
            if ($key === null || $key === '') {
                return App::config();
            }

            $parts = explode('.', $key, 2);
            $configName = array_shift($parts);

            if ($configName === null || $configName === '') {
                return $default;
            }

            $config = Config::name($configName);
            $nestedKey = $parts[0] ?? null;

            if ($nestedKey === null || $nestedKey === '') {
                return $config;
            }

            return $config->get($nestedKey, $default);
        });
    }

    public function lang(string $key, array $replace = [], ?string $locale = null): string|array
    {
        $this->assertExists();

        return App::meeting($this->package, static fn () => Lang::get($key, $replace, $locale));
    }

    public function path(string $relative = ''): string
    {
        $this->assertExists();

        $base = rtrim($this->engine->path($this->package), '/\\');

        return $relative === '' ? $base : $base . '/' . ltrim(str_replace('\\', '/', $relative), '/');
    }

    /**
     * Resolve a class inside the dependency app namespace.
     *
     * Examples:
     *   Model.OrderModel  => App\com_shop\Model\OrderModel
     *   Controller/HomeController
     */
    public function class(string $reference): string
    {
        $this->assertExists();

        $reference = trim(str_replace('\\', '/', $reference), '/');
        $reference = str_replace('/', '\\', $reference);

        if (str_starts_with($reference, 'App\\')) {
            return $reference;
        }

        $segments = explode('\\', $reference);
        $root = array_shift($segments);

        if ($root === null || $root === '') {
            throw new Exception('Class reference cannot be empty for package ' . $this->package . '.');
        }

        return 'App\\' . $this->package . '\\' . $root . ($segments !== [] ? '\\' . implode('\\', $segments) : '');
    }

    
    /**
     * Resolve a portal class name inside this app namespace.
     *
     * Examples:
     *   portal("Sms") => App\com_pinoox_sms\Portal\Sms
     *   portal()      => App\com_pinoox_sms\Portal\App
     */
        public function ensureAutoloader(): void
    {
        if (class_exists(App::class)) {
            try {
                App::autoloader($this->package);
            } catch (\Throwable) {
            }
        }
    }

    public function portal(string $name = "App"): string
    {
        $this->assertExists();
        $this->ensureAutoloader();

        $name = trim(str_replace("/", "\\", $name), "\\");
        if (str_starts_with($name, "App\\")) {
            return $name;
        }

        if (str_starts_with($name, "Portal\\")) {
            $name = substr($name, 7);
        }

        return "App\\" . $this->package . "\\Portal\\" . $name;
    }

    /**
     * Check if a portal exists and has a callable method.
     */
    public function hasPortalMethod(string $portalName, string $method): bool
    {
        if (!$this->exists()) {
            return false;
        }

        $portalClass = $this->portal($portalName);
        if (!class_exists($portalClass)) {
            return false;
        }

        return \Pinoox\Component\Source\Portal::hasMethod($portalClass, $method);
    }

    /**
     * Call a method on a portal within this app package context.
     */
    public function call(string $portalName, string $method, array $args = []): mixed
    {
        $this->assertExists();

        $portalClass = $this->portal($portalName);
        if (!class_exists($portalClass)) {
            throw new Exception("Portal class [{$portalClass}] does not exist in package [{$this->package}].");
        }

        return App::meeting($this->package, static function () use ($portalClass, $method, $args) {
            return $portalClass::$method(...$args);
        });
    }

    public function hasAction(string $name): bool
    {
        if (!$this->exists()) {
            return false;
        }

        $name = ltrim($name, '@&');

        return ActionRegistry::has($this->package, $name)
            || ActionRegistry::get($this->package, $this->package . '.' . $name) !== null;
    }

    public function actionUrl(string $name, array $parameters = [], bool $absolute = true): string
    {
        $this->assertExists();

        $name = ltrim($name, '@&');

        return App::url()->actionForPackage($this->package, '@' . $name, $parameters, $absolute);
    }

    /**
     * Execute callback only when the dependency app exists.
     */
    public function when(callable $callback, mixed $default = null): mixed
    {
        if (!$this->exists()) {
            return $default;
        }

        return $callback($this);
    }

    /**
     * Switch active app context temporarily.
     */
    public function meeting(callable $callback): mixed
    {
        $this->assertExists();

        return App::meeting($this->package, $callback);
    }

    private function assertExists(): void
    {
        if (!$this->exists()) {
            throw new Exception('Dependency app is not installed: ' . $this->package);
        }
    }
}
