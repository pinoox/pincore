<?php

namespace Pinoox\Component\AppEvent;

use Pinoox\Component\Http\Request;
use Pinoox\Portal\App\App;
use Pinoox\Portal\Event;

class AppWatchRegistry
{
    /** @var list<array<string, mixed>> */
    private static array $rules = [];

    private static bool $subscriberRegistered = false;

    /** @var array<string, true> */
    private static array $modelHooks = [];

    public static function absorb(string $package, AppRegisterCollector $collector): void
    {
        if ($collector->watches === []) {
            return;
        }

        foreach ($collector->watches as $rule) {
            self::$rules[] = array_merge($rule, ['registeredBy' => $package]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rules(): array
    {
        return self::$rules;
    }

    public static function integrate(): void
    {
        if (self::$rules === []) {
            return;
        }

        if (!self::$subscriberRegistered) {
            Event::addSubscriber(new AppWatchSubscriber());
            self::$subscriberRegistered = true;
        }

        self::registerModelHooks();
    }

    public static function reset(): void
    {
        self::$rules = [];
        self::$subscriberRegistered = false;
        self::$modelHooks = [];
    }

    /**
     * @param array<string, mixed> $rule
     */
    public static function add(string $package, array $rule): void
    {
        self::$rules[] = array_merge($rule, ['registeredBy' => $package]);
    }

    private static function registerModelHooks(): void
    {
        foreach (self::$rules as $rule) {
            if (($rule['kind'] ?? '') !== 'model') {
                continue;
            }

            $class = $rule['match']['class'] ?? null;
            $event = $rule['match']['event'] ?? null;
            if (!is_string($class) || !is_string($event) || $event === '') {
                continue;
            }

            $key = $class . '@' . $event;
            if (isset(self::$modelHooks[$key])) {
                continue;
            }

            if (!class_exists($class)) {
                continue;
            }

            $handler = $rule['handler'] ?? null;
            if (!is_callable($handler)) {
                continue;
            }

            $class::{$event}(function (...$args) use ($handler, $class, $event): void {
                try {
                    $request = App::getRequest();
                } catch (\Throwable) {
                    $request = Request::create('/');
                }

                $model = $args[0] ?? null;
                $handler(new AppWatchContext(
                    request: $request,
                    modelClass: $class,
                    modelEvent: $event,
                    model: $model,
                ));
            });

            self::$modelHooks[$key] = true;
        }
    }
}
