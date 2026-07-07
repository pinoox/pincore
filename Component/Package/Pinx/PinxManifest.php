<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Kernel\Exception;
use Pinoox\Component\Package\AppDependency;
use Pinoox\Component\Package\AppManifest;
use Pinoox\Component\Package\ManifestLabel;
use Pinoox\Component\Package\PackageName;
use Pinoox\Component\Template\Theme\ThemeManifest;

class PinxManifest
{

    public const FORMAT = 'pinx';

    public const FORMAT_VERSION = 1;

    public const TYPE_APP = 'app';

    public const TYPE_THEME = 'theme';

    public const MANIFEST_FILE = 'manifest.json';

    public const PAYLOAD_PREFIX = 'payload/';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data)
    {
    }

    public static function fromAppConfig(array $appConfig, string $type, array $pinxConfig = []): self
    {
        $package = (string) ($appConfig['package'] ?? '');
        $pathTheme = (string) ($appConfig['path-theme'] ?? 'theme');
        $themeName = (string) ($pinxConfig['theme_name'] ?? $appConfig['theme'] ?? 'default');
        $targetApp = (string) ($pinxConfig['target_app'] ?? $package);
        $themeManifest = $type === self::TYPE_THEME
            ? ThemeManifest::load($package, $themeName, $pathTheme)
            : null;

        if ($themeManifest !== null) {
            $themeManifest->validate($package);
            $targetApp = $themeManifest->hostPackage() ?: $targetApp;
        }

        $depends = AppDependency::fromAppConfig($appConfig);
        $packageLabel = AppManifest::displayName($package);
        $packageDescription = AppManifest::description($package);
        $packageLabels = AppManifest::labels($package);

        return new self([
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'type' => $type,
            'package' => $type === self::TYPE_THEME ? $themeName : $package,
            'name' => $themeManifest?->title() ?: $packageLabel,
            'description' => $themeManifest?->description() ?: $packageDescription,
            'labels' => $type === self::TYPE_THEME
                ? $themeManifest?->labels()
                : $packageLabels,
            'developer' => $themeManifest?->developer() ?: (string) ($appConfig['developer'] ?? ''),
            'version_name' => $themeManifest?->versionName() ?: (string) ($appConfig['version-name'] ?? '1.0'),
            'version_code' => $themeManifest?->versionCode() ?: (int) ($appConfig['version-code'] ?? 1),
            'minpin' => (int) ($pinxConfig['minpin'] ?? $appConfig['minpin'] ?? 0),
            'depends' => self::dependsForManifest($depends),
            'target_app' => $type === self::TYPE_THEME ? $targetApp : null,
            'theme_name' => $type === self::TYPE_THEME ? $themeName : null,
            'theme_meta' => $themeManifest?->toPinxThemeMeta(),
            'built_at' => gmdate('c'),
            'pinoox_version_name' => PinxVersion::pinoox()['name'],
            'pinoox_version_code' => PinxVersion::pinoox()['code'],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new Exception('Invalid pinx manifest.json');
        }

        return new self($data);
    }

    public function validate(): void
    {
        if (($this->data['format'] ?? '') !== self::FORMAT) {
            throw new Exception('Unsupported package format.');
        }

        $type = $this->type();

        if (!in_array($type, [self::TYPE_APP, self::TYPE_THEME], true)) {
            throw new Exception('Invalid package type in manifest.');
        }

        if ($this->package() === '') {
            throw new Exception('Manifest is missing package name.');
        }

        if ($type === self::TYPE_THEME) {
            if ($this->targetApp() === '') {
                throw new Exception('Theme package requires target_app in manifest.');
            }

            if ($this->themeName() === '') {
                throw new Exception('Theme package requires theme_name in manifest.');
            }

            self::assertValidAppPackage($this->targetApp(), 'target_app');
        }

        if ($type === self::TYPE_APP) {
            self::assertValidAppPackage($this->package(), 'package');
        }
    }

    private static function assertValidAppPackage(string $package, string $field): void
    {
        $error = PackageName::validationError($package);

        if ($error !== null) {
            throw new Exception(sprintf('Invalid %s in manifest: %s', $field, $error));
        }
    }

    public function type(): string
    {
        return (string) ($this->data['type'] ?? self::TYPE_APP);
    }

    public function package(): string
    {
        return (string) ($this->data['package'] ?? '');
    }

    public function name(): string
    {
        return (string) ($this->data['name'] ?? $this->package());
    }

    public function title(?string $locale = null): string
    {
        $labels = $this->labels();

        if (($labels['title'] ?? []) !== []) {
            $resolved = ManifestLabel::fromLocaleMap($labels['title'], $locale);

            if ($resolved !== '') {
                return $resolved;
            }
        }

        return $this->name();
    }

    public function description(?string $locale = null): string
    {
        $labels = $this->labels();

        if (($labels['description'] ?? []) !== []) {
            $resolved = ManifestLabel::fromLocaleMap($labels['description'], $locale);

            if ($resolved !== '') {
                return $resolved;
            }
        }

        return (string) ($this->data['description'] ?? '');
    }

    /**
     * @return array{title: array<string, string>, description: array<string, string>}
     */
    public function labels(): array
    {
        $labels = $this->data['labels'] ?? [];

        if (!is_array($labels)) {
            return ['title' => [], 'description' => []];
        }

        return [
            'title' => is_array($labels['title'] ?? null) ? $labels['title'] : [],
            'description' => is_array($labels['description'] ?? null) ? $labels['description'] : [],
        ];
    }

    public function developer(): string
    {
        return (string) ($this->data['developer'] ?? '');
    }

    public function versionName(): string
    {
        return (string) ($this->data['version_name'] ?? '1.0');
    }

    public function versionCode(): int
    {
        return (int) ($this->data['version_code'] ?? 1);
    }

    public function minpin(): int
    {
        return (int) ($this->data['minpin'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function dependsRaw(): array
    {
        $depends = $this->data['depends'] ?? [];

        return is_array($depends) ? $depends : [];
    }

    /**
     * @return list<array{package: string, optional: bool, min_code: ?int}>
     */
    public function depends(): array
    {
        return AppDependency::normalize($this->dependsRaw());
    }

    public function targetApp(): string
    {
        return (string) ($this->data['target_app'] ?? '');
    }

    public function themeName(): string
    {
        return (string) ($this->data['theme_name'] ?? '');
    }

    public function isApp(): bool
    {
        return $this->type() === self::TYPE_APP;
    }

    public function isTheme(): bool
    {
        return $this->type() === self::TYPE_THEME;
    }

    public function icon(): string
    {
        return (string) ($this->data['icon'] ?? '');
    }

    public function iconEntry(): string
    {
        return (string) ($this->data['icon_entry'] ?? '');
    }

    public function iconMime(): string
    {
        return (string) ($this->data['icon_mime'] ?? '');
    }

    public function hasIcon(): bool
    {
        return $this->iconEntry() !== '';
    }

    /**
     * @param array<string, mixed> $data
     */
    public function withData(array $data): self
    {
        return new self(array_replace($this->data, $data));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson(int $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): string
    {
        return json_encode($this->data, $flags) ?: '{}';
    }

    /**
     * @param list<array{package: string, optional: bool, min_code: ?int}> $rules
     * @return array<string, mixed>
     */
    private static function dependsForManifest(array $rules): array
    {
        if ($rules === []) {
            return [];
        }

        $depends = [];

        foreach ($rules as $rule) {
            $package = $rule['package'];

            if (!empty($rule['optional'])) {
                $depends[$package] = array_filter([
                    'optional' => true,
                    'min_code' => $rule['min_code'],
                ], static fn ($value) => $value !== null && $value !== false);
                continue;
            }

            if ($rule['min_code'] === null) {
                $depends[$package] = '*';
                continue;
            }

            $depends[$package] = '>=' . $rule['min_code'];
        }

        return $depends;
    }
}

