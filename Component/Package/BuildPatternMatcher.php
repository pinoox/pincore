<?php

namespace Pinoox\Component\Package;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\Gitignore;

/**
 * Match build exclude/include rules using .gitignore pattern semantics.
 */
final class BuildPatternMatcher
{
    private string $baseDir;

    /** @var list<string> */
    private array $excludePatterns;

    /** @var list<string> */
    private array $includePatterns;

    private string $exclusionRegex;

    private string $inclusionRegex;

    /**
     * @param list<string> $excludePatterns
     * @param list<string> $includePatterns
     */
    public function __construct(string $baseDir, array $excludePatterns, array $includePatterns = [])
    {
        $this->baseDir = rtrim(str_replace('\\', '/', realpath($baseDir) ?: $baseDir), '/');
        $this->excludePatterns = self::normalizePatterns($excludePatterns);
        $this->includePatterns = self::normalizeIncludePatterns($includePatterns);

        $rules = self::compileRules($this->excludePatterns, $this->includePatterns);
        $this->exclusionRegex = Gitignore::toRegex($rules);
        $this->inclusionRegex = Gitignore::toRegexMatchingNegatedPatterns($rules);
    }

    public function hasPatterns(): bool
    {
        return $this->excludePatterns !== [] || $this->includePatterns !== [];
    }

    public function isExcluded(string $relativePath): bool
    {
        $relativePath = self::normalizeRelativePath($relativePath);

        if ($relativePath === '' || !$this->hasPatterns()) {
            return false;
        }

        $ignored = false;

        if (preg_match($this->exclusionRegex, $relativePath)) {
            $ignored = true;
        }

        if (preg_match($this->inclusionRegex, $relativePath)) {
            $ignored = false;
        }

        return $ignored;
    }

    /**
     * @param list<string> $relativePaths
     * @return list<string>
     */
    public function filterIncludedRelativePaths(array $relativePaths): array
    {
        return array_values(array_filter(
            $relativePaths,
            fn (string $path): bool => !$this->isExcluded($path),
        ));
    }

    /**
     * Discover files on disk that match include patterns (force-include).
     *
     * @param list<string> $collectionDirectoryExcludes
     * @return array<string, string> map of relative path => absolute path
     */
    public function discoverForcedIncludes(array $collectionDirectoryExcludes = []): array
    {
        if ($this->includePatterns === [] || !is_dir($this->baseDir)) {
            return [];
        }

        $files = [];
        $finder = new Finder();
        $finder
            ->in($this->baseDir)
            ->files()
            ->ignoreDotFiles(false)
            ->ignoreVCS(true)
            ->ignoreUnreadableDirs();

        if ($collectionDirectoryExcludes !== []) {
            $finder->exclude($collectionDirectoryExcludes);
        }

        foreach ($finder as $file) {
            $realPath = $file->getRealPath();

            if ($realPath === false || !is_file($realPath)) {
                continue;
            }

            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            if (!preg_match($this->inclusionRegex, $relativePath)) {
                continue;
            }

            if ($this->isExcluded($relativePath)) {
                continue;
            }

            $files[$relativePath] = str_replace('\\', '/', $realPath);
        }

        return $files;
    }

    /**
     * @param array<string, string> $files
     * @param list<string> $collectionDirectoryExcludes
     * @return array<string, string>
     */
    public function applyToFiles(array $files, array $collectionDirectoryExcludes = []): array
    {
        if (!$this->hasPatterns()) {
            return $files;
        }

        $filtered = [];

        foreach ($files as $relativePath => $absolutePath) {
            if (!$this->isExcluded($relativePath)) {
                $filtered[$relativePath] = $absolutePath;
            }
        }

        foreach ($this->discoverForcedIncludes($collectionDirectoryExcludes) as $relativePath => $absolutePath) {
            $filtered[$relativePath] = $absolutePath;
        }

        return $filtered;
    }

    /**
     * @param list<string> $excludePatterns
     * @param list<string> $includePatterns
     */
    private static function compileRules(array $excludePatterns, array $includePatterns): string
    {
        $lines = [...$excludePatterns];

        foreach ($includePatterns as $pattern) {
            $lines[] = str_starts_with($pattern, '!') ? $pattern : '!' . $pattern;
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $patterns
     * @return list<string>
     */
    private static function normalizePatterns(array $patterns): array
    {
        $normalized = [];

        foreach ($patterns as $pattern) {
            $pattern = trim(str_replace('\\', '/', (string) $pattern));

            if ($pattern === '') {
                continue;
            }

            $normalized[] = $pattern;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param list<string> $patterns
     * @return list<string>
     */
    private static function normalizeIncludePatterns(array $patterns): array
    {
        $normalized = [];

        foreach ($patterns as $pattern) {
            $pattern = trim(str_replace('\\', '/', (string) $pattern));

            if ($pattern === '') {
                continue;
            }

            $normalized[] = ltrim($pattern, '!');
        }

        return array_values(array_unique($normalized));
    }

    private static function normalizeRelativePath(string $relativePath): string
    {
        return ltrim(str_replace('\\', '/', $relativePath), '/');
    }
}
