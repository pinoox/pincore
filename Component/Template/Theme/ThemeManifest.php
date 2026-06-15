<?php

namespace Pinoox\Component\Template\Theme;

use Pinoox\Component\Kernel\Exception;
use Pinoox\Component\Package\ManifestConfig;
use Pinoox\Component\Package\ManifestLabel;
use Pinoox\Component\Package\ManifestLangLoader;
use Pinoox\Component\Package\ManifestPinkerLoader;
use Pinoox\Portal\App\AppEngine as AppEnginePortal;

/**
 * Theme folder manifest (theme.php).
 *
 * Inheritance and theme metadata live inside theme/{name}/ — not app.php.
 */
final class ThemeManifest
{

    public const FILE = 'theme.php';

    public const FORMAT = 'pinoox-theme';

    public const FORMAT_VERSION = 1;

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        private readonly string $themePath,
        private readonly string $folderName,
        private array $data,
    ) {
    }

    public static function load(string $package, string $themeName, string $pathTheme = 'theme'): ?self
    {
        if ($package === '' || $themeName === '') {
            return null;
        }

        if (!AppEnginePortal::exists($package)) {
            return null;
        }

        $themePath = rtrim(str_replace('\\', '/', AppEnginePortal::path($package, $pathTheme . '/' . $themeName)), '/');

        return self::fromPath($themePath, $package, $themeName);
    }

    public static function fromPath(string $themePath, ?string $hostPackage = null, ?string $folderName = null): ?self
    {
        if (!is_dir($themePath)) {
            return null;
        }

        $folderName ??= basename(rtrim(str_replace('\\', '/', $themePath), '/'));
        $data = self::readConfig($themePath . '/' . self::FILE, $folderName, $hostPackage);

        return new self($themePath, $folderName, $data);
    }

    public static function hasManifest(string $themePath): bool
    {
        return is_file($themePath . '/' . self::FILE);
    }

    /**
     * @return array<string, ThemeManifest>
     */
    public static function discover(string $package, string $pathTheme = 'theme'): array
    {
        if (!AppEnginePortal::exists($package)) {
            return [];
        }

        $root = rtrim(str_replace('\\', '/', AppEnginePortal::path($package, $pathTheme)), '/');
        if (!is_dir($root)) {
            return [];
        }

        $themes = [];

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $root . '/' . $entry;
            if (!is_dir($path) || !self::hasManifest($path)) {
                continue;
            }

            $manifest = self::fromPath($path, $package, $entry);
            if ($manifest !== null) {
                $themes[$manifest->name()] = $manifest;
            }
        }

        ksort($themes);

        return $themes;
    }

    public function path(): string
    {
        return $this->themePath;
    }

    public function folder(): string
    {
        return $this->folderName;
    }

    public function name(): string
    {
        return (string) ($this->data['name'] ?? $this->folderName);
    }

    public function hostPackage(): string
    {
        $value = $this->data['package'] ?? $this->data['app'] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return list<string>
     */
    public function extends(): array
    {
        if (empty($this->data['extends'])) {
            return [];
        }

        return self::uniqueExtends(self::normalizeExtendsList($this->data['extends']));
    }

    public function title(?string $locale = null): string
    {
        $resolved = ManifestLabel::resolve(
            $this->data['title'] ?? null,
            $this->langPaths(),
            $locale,
            $this->name(),
            $this->fallbackLocale(),
        );

        return $resolved !== '' ? $resolved : $this->name();
    }

    public function description(?string $locale = null): string
    {
        return ManifestLabel::resolve(
            $this->data['description'] ?? null,
            $this->langPaths(),
            $locale,
            '',
            $this->fallbackLocale(),
        );
    }

    /**
     * @return array{title: array<string, string>, description: array<string, string>}
     */
    public function labels(): array
    {
        $paths = $this->langPaths();

        return [
            'title' => ManifestLabel::collect($this->data['title'] ?? null, $paths),
            'description' => ManifestLabel::collect($this->data['description'] ?? null, $paths),
        ];
    }

    public function developer(): string
    {
        return (string) ($this->data['developer'] ?? '');
    }

    public function copyright(): string
    {
        return (string) ($this->data['copyright'] ?? '');
    }

    public function cover(): string
    {
        return (string) ($this->data['cover'] ?? '');
    }

    public function versionName(): string
    {
        return (string) (
            $this->data['version-name']
            ?? $this->data['version']
            ?? '1.0'
        );
    }

    public function versionCode(): int
    {
        if (isset($this->data['version-code'])) {
            return (int) $this->data['version-code'];
        }

        if (isset($this->data['app_version'])) {
            return (int) $this->data['app_version'];
        }

        return 1;
    }

    public function hasApiShell(): bool
    {
        return (bool) ($this->data['api'] ?? false);
    }

    /**
     * Manifest value(s) from theme.php (supports dot notation).
     */
    public function config(?string $key = null, mixed $default = null): mixed
    {
        return ManifestConfig::get($this->data, $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'name' => $this->name(),
            'folder' => $this->folder(),
            'package' => $this->hostPackage(),
            'extends' => $this->extends(),
            'cover' => $this->cover(),
            'developer' => $this->developer(),
            'copyright' => $this->copyright(),
            'version_name' => $this->versionName(),
            'version_code' => $this->versionCode(),
            'api' => $this->hasApiShell(),
            'title' => $this->labels()['title'],
            'description' => $this->labels()['description'],
            'path' => $this->themePath,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPinxThemeMeta(): array
    {
        $labels = $this->labels();

        return [
            'name' => $this->name(),
            'app' => $this->hostPackage(),
            'developer' => $this->developer(),
            'copyright' => $this->copyright(),
            'version' => $this->versionName(),
            'app_version' => $this->versionCode(),
            'title' => $labels['title'] !== [] ? $labels['title'] : ['en' => $this->name()],
            'description' => $labels['description'],
            'extends' => $this->extends(),
            'cover' => $this->cover(),
            'api' => $this->hasApiShell(),
        ];
    }

    public function validate(?string $expectedHostPackage = null): void
    {
        if ($this->name() === '') {
            throw new Exception('Theme manifest is missing name.');
        }

        if ($expectedHostPackage !== null && $expectedHostPackage !== '') {
            $host = $this->hostPackage();
            if ($host !== '' && $host !== $expectedHostPackage) {
                throw new Exception(sprintf(
                    'Theme "%s" belongs to "%s", expected host app "%s".',
                    $this->name(),
                    $host,
                    $expectedHostPackage,
                ));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rawMeta(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readConfig(string $file, string $folderName, ?string $hostPackage): array
    {
        if (!is_file($file)) {
            return self::applyFallbacks([], $folderName, $hostPackage);
        }

        $data = ManifestPinkerLoader::resolve($file, ManifestPinkerLoader::themeDefaults());

        return self::applyFallbacks($data, $folderName, $hostPackage);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function applyFallbacks(array $data, string $folderName, ?string $hostPackage): array
    {
        if ($data === []) {
            $data = [
                'name' => $folderName,
                'package' => $hostPackage,
            ];
        }

        if ($hostPackage !== null && $hostPackage !== '') {
            $data['package'] ??= $hostPackage;
        }

        $data['name'] ??= $folderName;

        return $data;
    }

    /**
     * @return list<string>
     */
    private function langPaths(): array
    {
        $package = $this->hostPackage();

        if ($package === '') {
            return [rtrim(str_replace('\\', '/', $this->themePath), '/') . '/lang'];
        }

        return ManifestLangLoader::pathsForTheme($package, $this->themePath);
    }

    private function fallbackLocale(): string
    {
        return ManifestLabel::fallbackLocaleForPackage($this->hostPackage());
    }

    /**
     * @return list<string>
     */
    private static function normalizeExtendsList(mixed $extends): array
    {
        if (is_string($extends)) {
            $extends = trim($extends);

            return $extends === '' ? [] : [$extends];
        }

        if (!is_array($extends)) {
            return [];
        }

        $list = [];
        foreach ($extends as $item) {
            if (is_string($item) && trim($item) !== '') {
                $list[] = trim($item);
            }
        }

        return $list;
    }

    /**
     * @param list<string> $extends
     * @return list<string>
     */
    private static function uniqueExtends(array $extends): array
    {
        $unique = [];

        foreach ($extends as $extend) {
            if (!in_array($extend, $unique, true)) {
                $unique[] = $extend;
            }
        }

        return $unique;
    }
}

