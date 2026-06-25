<?php

namespace Pinoox\Component\Package\Pinx;

use PhpZip\ZipFile;

final class PinxIcon
{
    /** @var array<string, string> */
    private const MIME = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
    ];

    /**
     * @param array<string, mixed> $manifestData
     * @param array<string, string> $payloadFiles relative => absolute path
     * @param array<string, mixed> $appConfig
     * @return array<string, mixed>
     */
    public static function enrichManifest(array $manifestData, array $appConfig, array $payloadFiles): array
    {
        $icon = trim((string) ($appConfig['icon'] ?? 'icon.png'));

        if ($icon === '') {
            return $manifestData;
        }

        $relative = self::resolvePayloadRelative($icon, $payloadFiles);

        if ($relative === null) {
            return $manifestData;
        }

        $manifestData['icon'] = $relative;
        $manifestData['icon_entry'] = PinxManifest::PAYLOAD_PREFIX . $relative;
        $manifestData['icon_mime'] = self::mimeFromPath($relative);

        return $manifestData;
    }

    public static function readContents(PinxManifest $manifest, ZipFile $zip): ?string
    {
        $entry = $manifest->iconEntry();

        if ($entry === '' || !$zip->hasEntry($entry)) {
            return null;
        }

        $contents = $zip->getEntryContents($entry);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    public static function dataUri(PinxManifest $manifest, ZipFile $zip): ?string
    {
        $contents = self::readContents($manifest, $zip);

        if ($contents === null) {
            return null;
        }

        $mime = $manifest->iconMime() ?: 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }

    /**
     * @param array<string, string> $payloadFiles
     */
    private static function resolvePayloadRelative(string $icon, array $payloadFiles): ?string
    {
        $icon = ltrim(str_replace('\\', '/', $icon), '/');

        if (isset($payloadFiles[$icon])) {
            return $icon;
        }

        $basename = basename($icon);

        foreach ($payloadFiles as $relative => $_real) {
            if (basename($relative) === $basename) {
                return $relative;
            }
        }

        return null;
    }

    private static function mimeFromPath(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::MIME[$ext] ?? 'application/octet-stream';
    }
}
