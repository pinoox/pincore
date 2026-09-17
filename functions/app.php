<?php

use Pinoox\Component\Package\AppPackageContext;
use Pinoox\Component\Package\AppResource;
use Pinoox\Component\Package\AppDependency;
use Pinoox\Component\Package\AppEnv\AppEnvBridge;
use Pinoox\Portal\App\App;
use Pinoox\Portal\App\AppEngine;

if (!function_exists('use_app')) {
    /**
     * Access another app's config, lang, paths, actions, and classes.
     */
    function use_app(string $package): AppPackageContext
    {
        return AppResource::use($package);
    }
}

if (!function_exists('app_package')) {
    function app_package(string $package): AppPackageContext
    {
        return use_app($package);
    }
}

if (!function_exists('app_resource')) {
    function app_resource(string $reference, mixed $default = null, ?string $defaultPackage = null): mixed
    {
        return AppResource::get($reference, $default, $defaultPackage);
    }
}

if (!function_exists('app_dep_satisfied')) {
    /**
     * @param array<string, mixed>|list<string> $depends
     */
    function app_dep_satisfied(array $depends): bool
    {
        return AppDependency::isSatisfied(AppDependency::normalize($depends), AppEngine::___());
    }
}

if (!function_exists('app_env')) {
    /**
     * Read a resolved app/theme .env value (after AppEnvBridge applied catalog keys).
     */
    function app_env(?string $key = null, mixed $default = null, ?string $package = null): mixed
    {
        $package ??= App::package();

        if (!is_string($package) || $package === '') {
            return $default;
        }

        if ($key === null || $key === '') {
            return AppEnvBridge::effective($package);
        }

        return AppEnvBridge::get($package, $key, $default);
    }
}

if (!function_exists("app_portal")) {
    function app_portal(string $package, string $portal = "App"): string
    {
        return use_app($package)->portal($portal);
    }
}

if (!function_exists("has_portal_method")) {
    /**
     * Check if a method is available on a portal class or its underlying service.
     */
    function has_portal_method(string|object $portal, string $method): bool
    {
        return \Pinoox\Component\Source\Portal::hasMethod($portal, $method);
    }
}

if (!function_exists('is_sub_app')) {
    function is_sub_app(): bool
    {
        return \Pinoox\Component\Package\SubApp::isSubApp();
    }
}

if (!function_exists('sub_app_parent')) {
    function sub_app_parent(): ?string
    {
        return \Pinoox\Component\Package\SubApp::parent();
    }
}

if (!function_exists('sub_app_host')) {
    function sub_app_host(): ?string
    {
        return \Pinoox\Component\Package\SubApp::parent();
    }
}

if (!function_exists('is_sub_app_of')) {
    function is_sub_app_of(string|array $packages): bool
    {
        return \Pinoox\Component\Package\SubApp::isSubAppOf($packages);
    }
}

if (!function_exists('sub_app_context')) {
    function sub_app_context(?string $key = null, mixed $default = null): mixed
    {
        return \Pinoox\Component\Package\SubApp::context($key, $default);
    }
}
