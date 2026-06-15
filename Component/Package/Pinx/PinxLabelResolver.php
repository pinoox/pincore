<?php

namespace Pinoox\Component\Package\Pinx;

use PhpZip\ZipFile;
use Pinoox\Component\Package\ManifestLabel;
use Pinoox\Portal\App\AppEngine as AppEnginePortal;
use Pinoox\Portal\Lang;

/**
 * Resolve manifest label fields for pinx packages (build, read, CLI).
 */
final class PinxLabelResolver
{
    /**
     * @param array<string, mixed> $config app.php or theme.php payload
     * @param list<string> $langPaths
     * @return array{
     *     name: string,
     *     description: string,
     *     labels: array{title: array<string, string>, description: array<string, string>}
     * }
     */
    public static function resolve(array $config, array $langPaths = [], ?string $locale = null): array
    {
        $package = (string) ($config['package'] ?? '');
        $fallbackLocale = $package !== '' && AppEnginePortal::exists($package)
            ? ManifestLabel::fallbackLocaleForPackage($package)
            : self::cliLocale();

        $titleSource = $config['title'] ?? $config['name'] ?? null;
        $nameFallback = $config['name'] ?? $package;
        if (ManifestLabel::isLangRef($nameFallback) || ManifestLabel::isLocaleMap($nameFallback)) {
            $nameFallback = $package;
        }

        $name = ManifestLabel::resolve(
            $titleSource,
            $langPaths,
            $locale,
            is_string($nameFallback) ? $nameFallback : $package,
            $fallbackLocale,
        );

        $description = ManifestLabel::resolve(
            $config['description'] ?? null,
            $langPaths,
            $locale,
            '',
            $fallbackLocale,
        );

        return [
            'name' => $name !== '' ? $name : (is_string($package) && $package !== '' ? $package : ''),
            'description' => $description,
            'labels' => [
                'title' => ManifestLabel::collect($titleSource, $langPaths),
                'description' => ManifestLabel::collect($config['description'] ?? null, $langPaths),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function langPathsFromZip(ZipFile $zip): array
    {
        $root = self::materializeLangTree($zip, '');

        if ($root === null) {
            return [];
        }

        return [$root];
    }

    /**
     * @return list<string>
     */
    public static function langPathsFromThemeZip(ZipFile $zip): array
    {
        $paths = [];

        $themeLang = self::materializeLangTree($zip, '');
        if ($themeLang !== null) {
            $paths[] = $themeLang;
        }

        $payloadLang = self::materializeLangTree($zip, PinxManifest::PAYLOAD_PREFIX);
        if ($payloadLang !== null && !in_array($payloadLang, $paths, true)) {
            $paths[] = $payloadLang;
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function applyToManifestData(array $config, array $resolved): array
    {
        $data = $config;

        if (($resolved['name'] ?? '') !== '') {
            $data['name'] = $resolved['name'];
        }

        if (($resolved['description'] ?? '') !== '') {
            $data['description'] = $resolved['description'];
        }

        if (($resolved['labels']['title'] ?? []) !== [] || ($resolved['labels']['description'] ?? []) !== []) {
            $data['labels'] = $resolved['labels'];
        }

        return $data;
    }

    private static function cliLocale(): string
    {
        try {
            return (string) Lang::locale();
        } catch (\Throwable) {
            return 'en';
        }
    }

    private static function materializeLangTree(ZipFile $zip, string $prefix): ?string
    {
        $prefix = rtrim(str_replace('\\', '/', $prefix), '/');
        $needle = $prefix === '' ? 'lang/' : $prefix . '/lang/';
        $tmpRoot = rtrim(sys_get_temp_dir(), '/\\') . '/pinx_lang_' . uniqid('', true);
        $langRoot = $tmpRoot . '/lang';
        $found = false;

        foreach ($zip->getListFiles() as $entry) {
            $entry = str_replace('\\', '/', $entry);

            if (!str_contains($entry, $needle)) {
                continue;
            }

            if (!preg_match('#' . preg_quote($needle, '#') . '([a-z]{2}(?:-[a-z]{2})?)/([^/]+)\.lang\.php$#i', $entry, $matches)) {
                continue;
            }

            $locale = $matches[1];
            $group = $matches[2];
            $targetDir = $langRoot . '/' . $locale;

            if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
                continue;
            }

            file_put_contents(
                $targetDir . '/' . $group . '.lang.php',
                $zip->getEntryContents($entry),
            );
            $found = true;
        }

        return $found ? $langRoot : null;
    }
}
