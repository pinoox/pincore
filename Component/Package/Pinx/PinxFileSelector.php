<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Package\BuildPatternMatcher;
use Pinoox\Component\Package\GitignorePathMatcher;
use Symfony\Component\Finder\Finder;

class PinxFileSelector
{
    /**
     * @param array{
     *     gitignore?: bool,
     *     exclude?: list<string>,
     *     include?: list<string>,
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
            ->exclude(PinxPaths::collectionDirectoryExcludes());

        if (!empty($buildConfig['gitignore'])) {
            $finder->ignoreDotFiles(false);

            if (!$this->isRepositoryIgnoredPath($sourcePath)) {
                $matcher = new GitignorePathMatcher($sourcePath);

                if ($matcher->shouldUseFinderGitignore()) {
                    $finder->ignoreVCSIgnored(true);
                }
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
            ->exclude(PinxPaths::collectionDirectoryExcludes());

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
     *     include?: list<string>,
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

            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            if (!empty($buildConfig['gitignore']) && str_ends_with($relativePath, '.gitignore')) {
                continue;
            }

            $files[$relativePath] = $realPath;
        }

        if (!empty($buildConfig['gitignore']) && !$this->isRepositoryIgnoredPath($sourcePath)) {
            $files = $this->withoutGitignoredFiles($sourcePath, $files);
        }

        $files = $this->applyBuildPatterns($sourcePath, $files, $buildConfig);

        foreach ($buildConfig['always_include'] ?? [] as $entry) {
            [$relativeDir, $payloadPrefix] = $this->resolveAlwaysIncludeEntry($entry);

            foreach ($this->forcedDirectoryFiles($sourcePath, $relativeDir) as $relativePath => $realPath) {
                $files[$this->remapAlwaysIncludePath($relativePath, $relativeDir, $payloadPrefix)] = $realPath;
            }
        }

        return $files;
    }

    /**
     * @param array<string, string> $files
     * @return array<string, string>
     */
    private function withoutGitignoredFiles(string $sourcePath, array $files): array
    {
        $sourceRoot = rtrim(str_replace('\\', '/', realpath($sourcePath) ?: $sourcePath), '/');
        $matcher = new GitignorePathMatcher($sourceRoot);

        $absoluteByRelative = [];

        foreach (array_keys($files) as $relativePath) {
            $absoluteByRelative[$relativePath] = $sourceRoot . '/' . $relativePath;
        }

        $includedLookup = array_fill_keys(
            $matcher->filterIncludedPaths(array_values($absoluteByRelative)),
            true,
        );

        $filtered = [];

        foreach ($absoluteByRelative as $relativePath => $absolutePath) {
            if (isset($includedLookup[$absolutePath])) {
                $filtered[$relativePath] = $files[$relativePath];
            }
        }

        return $filtered;
    }

    /**
     * @param array{
     *     exclude?: list<string>,
     *     include?: list<string>
     * } $buildConfig
     * @param array<string, string> $files
     * @return array<string, string>
     */
    private function applyBuildPatterns(string $sourcePath, array $files, array $buildConfig): array
    {
        $matcher = new BuildPatternMatcher(
            $sourcePath,
            $buildConfig['exclude'] ?? [],
            $buildConfig['include'] ?? [],
        );

        return $matcher->applyToFiles($files, PinxPaths::collectionDirectoryExcludes());
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
}
