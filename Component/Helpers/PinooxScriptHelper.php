<?php

namespace Pinoox\Component\Helpers;

use Pinoox\Component\Template\Frontend\FrontendConfig;
use Pinoox\Component\User\AuthConfig;
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

        $auth = self::resolveAuthClient();
        if ($auth !== null) {
            $defaults['auth'] = $auth;
        }

        return array_replace_recursive($defaults, $page);
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
