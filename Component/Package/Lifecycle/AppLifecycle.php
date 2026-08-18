<?php

namespace Pinoox\Component\Package\Lifecycle;

final class AppLifecycle
{
    public const INSTALL = 'install';

    public const UPDATE = 'update';

    public const UNINSTALL = 'uninstall';

    public const RESET = 'reset';

    /**
     * @var array<string, list<callable(AppLifecycleContext): void>>
     */
    private array $handlers = [
        self::INSTALL => [],
        self::UPDATE => [],
        self::UNINSTALL => [],
        self::RESET => [],
    ];

    public function onInstall(callable $handler): self
    {
        $this->handlers[self::INSTALL][] = $handler;

        return $this;
    }

    public function onUpdate(callable $handler): self
    {
        $this->handlers[self::UPDATE][] = $handler;

        return $this;
    }

    public function onUninstall(callable $handler): self
    {
        $this->handlers[self::UNINSTALL][] = $handler;

        return $this;
    }

    public function onReset(callable $handler): self
    {
        $this->handlers[self::RESET][] = $handler;

        return $this;
    }

    /**
     * @return list<callable(AppLifecycleContext): void>
     */
    public function handlers(string $action): array
    {
        return $this->handlers[$action] ?? [];
    }

    public static function isAction(string $action): bool
    {
        return in_array($action, [self::INSTALL, self::UPDATE, self::UNINSTALL, self::RESET], true);
    }

    /**
     * Load lifecycle.php: registrar closure, action => callable map, or empty if no return.
     */
    public static function fromFile(string $path): self
    {
        $life = new self();

        if (!is_file($path)) {
            return $life;
        }

        $returned = include $path;

        if (is_callable($returned)) {
            $returned($life);

            return $life;
        }

        if (!is_array($returned)) {
            return $life;
        }

        foreach ([self::INSTALL, self::UPDATE, self::UNINSTALL, self::RESET] as $action) {
            if (isset($returned[$action]) && is_callable($returned[$action])) {
                $life->handlers[$action][] = $returned[$action];
            }
        }

        return $life;
    }
}
