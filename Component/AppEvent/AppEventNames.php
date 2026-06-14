<?php

namespace Pinoox\Component\AppEvent;

final class AppEventNames
{
    // Boot lifecycle
    public const BOOTING = 'app.booting';

    public const BOOTED = 'app.booted';

    public const ROUTES = 'app.routes';

    public const API = 'app.api';

    // HTTP / request lifecycle (dispatched by AppCoreEventSubscriber)
    public const ROUTE_MATCHED = 'app.route.matched';

    public const CONTROLLER = 'app.controller';

    public const RESPONSE = 'app.response';

    public const EXCEPTION = 'app.exception';

    public const TERMINATE = 'app.terminate';

    public static function package(string $base, string $package): string
    {
        return $base . '.' . $package;
    }

    /**
     * Route-name channel, e.g. app.route.app.run
     */
    public static function route(string $routeName): string
    {
        return 'app.route.' . $routeName;
    }

    /**
     * API route-name channel, e.g. app.api.auth.login
     */
    public static function apiRoute(string $routeName): string
    {
        return 'app.api.' . $routeName;
    }

    /**
     * Controller channel, e.g. app.controller.App.Controller.MainController.index
     */
    public static function controller(string $class, ?string $method = null): string
    {
        $name = 'app.controller.' . str_replace('\\', '.', ltrim($class, '\\'));
        if ($method !== null && $method !== '') {
            $name .= '.' . $method;
        }

        return $name;
    }

    /**
     * @return list<string>
     */
    public static function coreRequestEvents(): array
    {
        return [
            self::ROUTE_MATCHED,
            self::CONTROLLER,
            self::RESPONSE,
            self::EXCEPTION,
            self::TERMINATE,
        ];
    }
}
