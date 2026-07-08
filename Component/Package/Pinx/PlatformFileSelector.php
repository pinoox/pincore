<?php

namespace Pinoox\Component\Package\Pinx;

use Pinoox\Component\Package\BuildPatternMatcher;
use Pinoox\Component\Package\GitignorePathMatcher;
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

        foreach ($this->forcedHtaccessFiles($projectRoot) as $relativePath => $absolutePath) {
            $files[$relativePath] = $absolutePath;
        }

        if (!empty($buildConfig['gitignore'])) {
            $files = $this->withoutGitignoredFiles($projectRoot, $files);
        }

        $files = $this->applyBuildPatterns($projectRoot, $files, $buildConfig);

        ksort($files);

        return $files;
    }

    /**
     * @param array{
     *     gitignore?: bool
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
            ->exclude(PlatformBuildConfig::collectionDirectoryExcludes());

        if (!empty($buildConfig['gitignore'])) {
            $matcher = new GitignorePathMatcher($projectRoot);

            if ($matcher->shouldUseFinderGitignore()) {
                $finder->ignoreVCSIgnored(true);
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
     * @return array<string, string>
     */
    private function forcedHtaccessFiles(string $projectRoot): array
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
            ->exclude(PlatformBuildConfig::collectionDirectoryExcludes());

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

    /**
     * @param array<string, string> $files
     * @return array<string, string>
     */
    private function withoutGitignoredFiles(string $projectRoot, array $files): array
    {
        $projectRoot = rtrim(str_replace('\\', '/', realpath($projectRoot) ?: $projectRoot), '/');
        $matcher = new GitignorePathMatcher($projectRoot);

        $absoluteByRelative = [];

        foreach (array_keys($files) as $relativePath) {
            $absoluteByRelative[$relativePath] = $projectRoot . '/' . $relativePath;
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
    private function applyBuildPatterns(string $projectRoot, array $files, array $buildConfig): array
    {
        $matcher = new BuildPatternMatcher(
            $projectRoot,
            $buildConfig['exclude'] ?? [],
            $buildConfig['include'] ?? [],
        );

        return $matcher->applyToFiles($files, PlatformBuildConfig::collectionDirectoryExcludes());
    }
}
