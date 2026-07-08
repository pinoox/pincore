<?php

namespace Pinoox\Component\Package\Pinx;

use Symfony\Component\Finder\Finder;

final class PlatformFileSelector
{
    /**
     * @param array{
     *     gitignore?: bool,
     *     exclude?: list<string>,
     *     include?: list<string>,
     *     exclude_theme_src?: bool
     * } $buildConfig
     * @return array<string, string> map of relative path => absolute path
     */
    public function payloadFiles(string $projectRoot, array $buildConfig): array
    {
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $files = [];

        foreach ($this->files($projectRoot, $buildConfig)->files() as $file) {
            $realPath = $file->getRealPath();

            if ($realPath === false || !is_file($realPath)) {
                continue;
            }

            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            if ($this->shouldSkipRelativePath($relativePath, $buildConfig)) {
                continue;
            }

            $files[$relativePath] = $realPath;
        }

        foreach ($buildConfig['include'] ?? [] as $includePath) {
            $absolutePath = $projectRoot . '/' . ltrim(str_replace('\\', '/', $includePath), '/');

            if (!is_file($absolutePath)) {
                continue;
            }

            $relativePath = ltrim(str_replace('\\', '/', $includePath), '/');
            $files[$relativePath] = $absolutePath;
        }

        foreach ($this->forcedHtaccessFiles($projectRoot, $buildConfig) as $relativePath => $absolutePath) {
            $files[$relativePath] = $absolutePath;
        }

        ksort($files);

        return $files;
    }

    /**
     * @param array{
     *     gitignore?: bool,
     *     exclude?: list<string>
     * } $buildConfig
     */
    public function files(string $projectRoot, array $buildConfig): Finder
    {
        $finder = new Finder();
        $finder
            ->in($projectRoot)
            ->files()
            ->ignoreDotFiles(false)
            ->ignoreVCS(true)
            ->ignoreUnreadableDirs()
            ->exclude(PlatformBuildConfig::directoryExcludes());

        if (!empty($buildConfig['gitignore'])) {
            $finder->ignoreVCSIgnored(!$this->isRepositoryIgnoredPath($projectRoot));
        }

        foreach ($buildConfig['exclude'] ?? [] as $excludePath) {
            if ($this->isDirectoryExclude($excludePath)) {
                continue;
            }

            if (str_contains($excludePath, '*')) {
                $this->excludeWildcardPaths($finder, $projectRoot, $excludePath);
                continue;
            }

            $absolutePath = $projectRoot . '/' . ltrim($excludePath, '/');

            if (is_dir($absolutePath) || is_file($absolutePath)) {
                $finder->notPath($excludePath);
            }
        }

        return $finder;
    }

    /**
     * @param array{exclude_theme_src?: bool} $buildConfig
     */
    private function shouldSkipRelativePath(string $relativePath, array $buildConfig): bool
    {
        if (str_ends_with($relativePath, '.gitignore')) {
            return true;
        }

        if (!empty($buildConfig['exclude_theme_src'])
            && preg_match('#(^|/)theme/[^/]+/src(/|$)#', $relativePath) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param array{exclude?: list<string>} $buildConfig
     * @return array<string, string>
     */
    private function forcedHtaccessFiles(string $projectRoot, array $buildConfig): array
    {
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $files = [];
        $finder = new Finder();
        $finder
            ->in($projectRoot)
            ->files()
            ->name('.htaccess')
            ->ignoreDotFiles(false)
            ->ignoreVCS(true)
            ->ignoreUnreadableDirs()
            ->exclude(PlatformBuildConfig::directoryExcludes());

        if (!empty($buildConfig['gitignore'])) {
            $finder->ignoreVCSIgnored(false);
        }

        foreach ($buildConfig['exclude'] ?? [] as $excludePath) {
            $excludePath = trim(str_replace('\\', '/', $excludePath), '/');

            if ($excludePath === '') {
                continue;
            }

            $finder->notPath($excludePath);
        }

        foreach ($finder as $file) {
            $realPath = $file->getRealPath();

            if ($realPath === false || !is_file($realPath)) {
                continue;
            }

            $relativePath = str_replace('\\', '/', $file->getRelativePathname());
            $files[$relativePath] = $realPath;
        }

        return $files;
    }

    private function isDirectoryExclude(string $excludePath): bool
    {
        $excludePath = trim(str_replace('\\', '/', $excludePath), '/');

        if ($excludePath === '') {
            return false;
        }

        if (str_contains($excludePath, '*')) {
            $base = explode('/*', $excludePath, 2)[0];

            return in_array($base, PlatformBuildConfig::directoryExcludes(), true);
        }

        return in_array($excludePath, PlatformBuildConfig::directoryExcludes(), true);
    }

    private function excludeWildcardPaths(Finder $finder, string $projectRoot, string $wildcardPath): void
    {
        $parts = explode('/*', $wildcardPath, 2);
        $baseDir = $parts[0];
        $remainingPath = isset($parts[1]) ? trim($parts[1], '/') : '';
        $baseAbsolute = rtrim($projectRoot, '/\\') . '/' . ltrim($baseDir, '/\\');

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

            $relativePath = str_replace('\\', '/', substr($actualPath, strlen(rtrim($projectRoot, '/\\')) + 1));
            $finder->notPath($relativePath);
        }
    }

    private function isRepositoryIgnoredPath(string $sourcePath): bool
    {
        $normalized = str_replace('\\', '/', realpath($sourcePath) ?: $sourcePath);
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
            default => false,
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
}
