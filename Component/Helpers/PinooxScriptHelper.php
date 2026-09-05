<?php

namespace Pinoox\Component\Helpers;

use Pinoox\Component\Package\AppManifest;
use Pinoox\Component\Template\Frontend\FrontendConfig;
use Pinoox\Component\Template\Theme\ThemeContext;
use Pinoox\Component\Template\Theme\ThemeContextRegistry;
use Pinoox\Component\User\AuthConfig;
use Pinoox\Portal\App\App;
use Pinoox\Portal\Url;
use Pinoox\Portal\View;

final class PinooxScriptHelper
{
    /**
     * Runtime + page props for window.__PINOOX__.
     *
     * Always includes `url.*`. When `auth.client` is enabled in app.php
     * (default true; legacy: via, expose, bootstrap), also includes `auth`
     * from AuthConfig::forClient(). Pass `$page['auth']` from Flow to override.
     *
     * When a theme context with a non-empty `path` is active:
     * - `url.BASE` = app path + context path (path-only)
     * - `url.AREA` = absolute app URL + context path (e.g. https://domain.com/panel)
     * Otherwise `url.AREA` equals `url.APP`.
     *
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    public static function bootstrap(array $page = []): array
    {
        $url = Url::accessor()->toArray();

        $defaults = [
            'url' => [
                'APP' => $url['app'],
                'BASE' => $url['appPath'],
                'AREA' => $url['app'],
                'API' => $url['api'],
                'SITE' => $url['site'],
                'DOMAIN' => $url['domain'],
                'PATH' => $url['path'],
                'THEME' => $url['theme'],
                'RES' => $url['resources'],
                'AVATAR' => $url['avatar'],
                'APP_ICON' => $url['appIcon'],
            ],
        ];

        $contextPath = self::activeContextPath();
        if ($contextPath !== null && $contextPath !== '') {
            $defaults['url']['BASE'] = Url::to($contextPath, Url::APP_PATH);
            $defaults['url']['AREA'] = rtrim(Url::to($contextPath, Url::APP), '/');
        }

        $auth = self::resolveAuthClient();
        if ($auth !== null) {
            $defaults['auth'] = $auth;
        }

        return array_replace_recursive($defaults, $page);
    }

    /**
     * Non-empty path from the active theme context, or null.
     */
    private static function activeContextPath(): ?string
    {
        try {
            $package = App::package();
            if (!is_string($package) || $package === '') {
                return null;
            }

            $active = ThemeContext::active($package);
            if ($active === null || $active === '') {
                return null;
            }

            $config = AppManifest::load($package);
            $ctx = ThemeContextRegistry::context($config, $active);
            if (!array_key_exists('path', $ctx)) {
                return null;
            }

            $path = $ctx['path'];
            if (!is_string($path)) {
                return null;
            }

            $path = trim($path, '/');

            return $path !== '' ? $path : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function resolveAuthClient(): ?array
    {
        try {
            return AuthConfig::forClient();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $page
     */
    public static function bootstrapTags(array $page = []): string
    {
        return '<script>window.__PINOOX__ = '
            . json_encode(self::bootstrap($page), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            . ';</script>';
    }

    public static function tags(?string $template = null): string
    {
        $themePath = View::path()->current();
        $config = FrontendConfig::forThemePath($themePath);
        $template ??= self::templateName($config);

        if ($template === '' || !View::exists($template)) {
            return '';
        }

        $content = trim(View::render($template, [], exist: false));

        if ($content === '') {
            return '';
        }

        return '<script>' . $content . '</script>';
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function templateName(array $config): string
    {
        $name = $config['pinoox'] ?? $config['pinoox_js'] ?? 'pinoox';

        if (!is_string($name) || $name === '') {
            return 'pinoox';
        }

        $name = str_replace('\\', '/', $name);
        $name = basename($name);

        return str_ends_with($name, '.twig')
            ? substr($name, 0, -5)
            : preg_replace('/\.js$/', '', $name) ?? $name;
    }
}
