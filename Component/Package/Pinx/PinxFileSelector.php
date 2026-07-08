<?php

namespace Pinoox\Component\Package\Pinx;

use Symfony\Component\Finder\Finder;

class PinxFileSelector
{
    /**
     * @param array{
     *     gitignore?: bool,
     *     exclude?: list<string>,
     *     include_themes?: list<string>
     * } $buildConfig
     */
    public function files(string $sourcePath, array $buildConfig): Finder
    {
        $finder = new Finder();
        $finder
            ->in($sourcePath)
            ->files()
            ->ignoreVCS(true)
            ->ignoreUnreadableDirs()
            ->exclude(PinxPaths::directoryExcludes());

        if (!empty($buildConfig['gitignore'])) {
            $finder->ignoreVCSIgnored(!$this->isRepositoryIgnoredPath($sourcePath));
        }

        foreach ($buildConfig['exclude'] ?? [] as $excludePath) {
            if ($this->isDirectoryExclude($excludePath)) {
                continue;
            }

            if (str_contains($excludePath, '*')) {
                $this->excludeWildcardPaths($finder, $sourcePath, $excludePath);
                continue;
            }

            $absolutePath = rtrim($sourcePath, '/\\') . '/' . ltrim($excludePath, '/\\');
            if (is_dir($absolutePath) || is_file($absolutePath)) {
                $finder->notPath($excludePath);
            }
        }

        $this->applyThemeFilter($finder, $sourcePath, $buildConfig['include_themes'] ?? []);

        return $finder;
    }

    /**
     * Collect files from a directory that must ship in the package even when gitignored.
     *
     * @return array<string, string> map of relative path => absolute path
     */
    public function forcedDirectoryFiles(string $sourcePath, string $relativeDir): array
    {
        $absolute = rtrim(str_replace('\\', '/', $sourcePath), '/') . '/' . ltrim(str_replace('\\', '/', $relativeDir), '/');
        if (!is_dir($absolute)) {
            return [];
        }

        $files = [];
        $finder = new Finder();
        $finder
            ->in($absolute)
            ->files()
            ->ignoreVCS(true)
            ->ignoreUnreadableDirs()
            ->exclude(PinxPaths::directoryExcludes());

        $prefix = trim(str_replace('\\', '/', $relativeDir), '/');

        foreach ($finder as $file) {
            $realPath = $file->getRealPath();
            if ($realPath === false) {
                continue;
            }

            $relativePath = $prefix . '/' . str_replace('\\', '/', $file->getRelativePathname());
            $files[$relativePath] = $realPath;
        }

        return $files;
    }

    /**
     * @param array{
     *     gitignore?: bool,
     *     exclude?: list<string>,
     *     include_themes?: list<string>,
     *     always_include?: list<string>
     * } $buildConfig
     * @return array<string, string> map of relative path => absolute path
     */
    public function payloadFiles(string $sourcePath, array $buildConfig): array
    {
        $files = [];

        foreach ($this->files($sourcePath, $buildConfig)->files() as $file) {
            $realPath = $file->getRealPath();
            if ($realPath === false) {
                continue;
            }

            $files[str_replace('\\', '/', $file->getRelativePathname())] = $realPath;
        }

        foreach ($buildConfig['always_include'] ?? [] as $entry) {
            [$relativeDir, $payloadPrefix] = $this->resolveAlwaysIncludeEntry($entry);

            foreach ($this->forcedDirectoryFiles($sourcePath, $relativeDir) as $relativePath => $realPath) {
                $files[$this->remapAlwaysIncludePath($relativePath, $relativeDir, $payloadPrefix)] = $realPath;
            }
        }

        return $files;
    }

    /**
     * When the build source lives under a gitignored tree (e.g. test runtime apps),
     * Symfony Finder would select zero files with ignoreVCSIgnored(true).
     */
    private function isRepositoryIgnoredPath(string $sourcePath): bool
    {
        $normalized = str_replace('\\', '/', realpath($sourcePath) ?: $sourcePath);

        if (str_contains($normalized, '/tests/Fixtures/runtime/')) {
            return true;
        }

        $gitRoot = $this->gitRepositoryRoot($normalized);

        if ($gitRoot === null) {
            return false;
        }

        $process = new \Symfony\Component\Process\Process(
            ['git', '-C', $gitRoot, 'check-ignore', '-q', $normalized],
        );
        $process->run();

        return match ($process->getExitCode()) {
            0 => true,
            1 => false,
            default => str_contains($normalized, '/tests/'),
        };
    }

    private function gitRepositoryRoot(string $path): ?string
    {
        $dir = is_dir($path) ? $path : dirname($path);

        while ($dir !== dirname($dir)) {
            if (is_dir($dir . '/.git')) {
                return $dir;
            }

            $dir = dirname($dir);
        }

        return null;
    }

    /**
     * @param list<string> $includeThemes
     */
    private function applyThemeFilter(Finder $finder, string $sourcePath, array $includeThemes): void
    {
        if ($includeThemes === []) {
            return;
        }

        $themeBasePath = rtrim($sourcePath, '/\\') . '/theme';
        if (!is_dir($themeBasePath)) {
            return;
        }

        $themeFinder = new Finder();
        $themeFinder->in($themeBasePath)->directories()->depth(0);

        foreach ($themeFinder as $dir) {
            $themeName = $dir->getRelativePathname();
            if (!in_array($themeName, $includeThemes, true)) {
                $finder->notPath('theme/' . $themeName);
            }
        }
    }

    /**
     * @param string|array{path: string, as?: string} $entry
     * @return array{0: string, 1: ?string}
     */
    private function resolveAlwaysIncludeEntry(string|array $entry): array
    {
        if (is_string($entry)) {
            return [$entry, null];
        }

        $path = trim((string) ($entry['path'] ?? ''), '/');
        $as = trim((string) ($entry['as'] ?? ''), '/');

        return [$path, $as !== '' ? $as : null];
    }

    private function remapAlwaysIncludePath(string $relativePath, string $sourceDir, ?string $payloadPrefix): string
    {
        if ($payloadPrefix === null) {
            return $relativePath;
        }

        $normalizedRelative = str_replace('\\', '/', $relativePath);
        $normalizedSource = trim(str_replace('\\', '/', $sourceDir), '/');

        if ($normalizedSource !== '' && str_starts_with($normalizedRelative, $normalizedSource . '/')) {
            return $payloadPrefix . '/' . substr($normalizedRelative, strlen($normalizedSource) + 1);
        }

        if ($normalizedRelative === $normalizedSource) {
            return $payloadPrefix;
        }

        return $payloadPrefix . '/' . ltrim($normalizedRelative, '/');
    }

    private function isDirectoryExclude(string $excludePath): bool
    {
        $excludePath = trim(str_replace('\\', '/', $excludePath), '/');

        if ($excludePath === '') {
            return false;
        }

        if (str_contains($excludePath, '*')) {
            $base = explode('/*', $excludePath, 2)[0];

            return in_array($base, PinxPaths::directoryExcludes(), true);
        }

        return in_array($excludePath, PinxPaths::directoryExcludes(), true);
    }

    private function excludeWildcardPaths(Finder $finder, string $packagePath, string $wildcardPath): void
    {
        $parts = explode('/*', $wildcardPath, 2);
        $baseDir = $parts[0];
        $remainingPath = isset($parts[1]) ? trim($parts[1], '/') : '';
        $baseAbsolute = rtrim($packagePath, '/\\') . '/' . ltrim($baseDir, '/\\');

        if (!is_dir($baseAbsolute)) {
            return;
        }

        $subDirectories = (new Finder())
            ->in($baseAbsolute)
            ->directories()
            ->depth(0)
            ->name('*')
            ->sortByName();

        foreach ($subDirectories as $dir) {
            $actualPath = $dir->getRealPath() . ($remainingPath !== '' ? '/' . $remainingPath : '');

            if (!is_dir($actualPath) && !is_file($actualPath)) {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($actualPath, strlen(rtrim($packagePath, '/\\')) + 1));
            $finder->notPath($relativePath);
        }
    }
}

