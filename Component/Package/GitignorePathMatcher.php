<?php

namespace Pinoox\Component\Package;

use Symfony\Component\Finder\Gitignore;

/**
 * Match paths against nested .gitignore rules (root and subdirectories).
 */
final class GitignorePathMatcher
{
    private string $baseDir;

    /** @var array<string, array{0: string, 1: string}|null> */
    private array $gitignoreFilesCache = [];

    /** @var array<string, bool> */
    private array $ignoredPathsCache = [];

    public function __construct(string $baseDir)
    {
        $this->baseDir = $this->normalizePath($baseDir);

        foreach ([$this->baseDir, ...$this->parentDirectoriesUpwards($this->baseDir)] as $directory) {
            if (@is_dir("{$directory}/.git")) {
                $this->baseDir = $directory;
                break;
            }
        }
    }

    public function isIgnored(string $path): bool
    {
        $path = $this->normalizePath($path);

        if (!str_starts_with($path, $this->baseDir)) {
            $path = $this->baseDir . '/' . ltrim($path, '/');
        }

        if (is_dir($path) && !str_ends_with($path, '/')) {
            $path .= '/';
        }

        return $this->matchesIgnored($path);
    }

    private function matchesIgnored(string $fileRealPath): bool
    {
        if (isset($this->ignoredPathsCache[$fileRealPath])) {
            return $this->ignoredPathsCache[$fileRealPath];
        }

        $ignored = false;

        foreach ($this->parentDirectoriesDownwards($fileRealPath) as $parentDirectory) {
            if ($this->matchesIgnored($parentDirectory)) {
                break;
            }

            $fileRelativePath = substr($fileRealPath, \strlen($parentDirectory) + 1);

            if (null === $regexps = $this->readGitignoreFile("{$parentDirectory}/.gitignore")) {
                continue;
            }

            [$exclusionRegex, $inclusionRegex] = $regexps;

            if (preg_match($exclusionRegex, $fileRelativePath)) {
                $ignored = true;

                continue;
            }

            if (preg_match($inclusionRegex, $fileRelativePath)) {
                $ignored = false;
            }
        }

        return $this->ignoredPathsCache[$fileRealPath] = $ignored;
    }

    /**
     * @return list<string>
     */
    private function parentDirectoriesUpwards(string $from): array
    {
        $parentDirectories = [];
        $parentDirectory = $from;

        while (true) {
            $newParentDirectory = \dirname($parentDirectory);

            if ($newParentDirectory === $parentDirectory) {
                break;
            }

            $parentDirectories[] = $parentDirectory = $newParentDirectory;
        }

        return $parentDirectories;
    }

    /**
     * @return list<string>
     */
    private function parentDirectoriesDownwards(string $fileRealPath): array
    {
        $baseDir = $this->baseDir;

        return array_reverse(
            array_filter(
                $this->parentDirectoriesUpwards($fileRealPath),
                static fn (string $directory): bool => str_starts_with($directory, $baseDir),
            ),
        );
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function readGitignoreFile(string $path): ?array
    {
        if (\array_key_exists($path, $this->gitignoreFilesCache)) {
            return $this->gitignoreFilesCache[$path];
        }

        if (!is_file($path) || !is_readable($path)) {
            return $this->gitignoreFilesCache[$path] = null;
        }

        $gitignoreFileContent = file_get_contents($path);

        if (!is_string($gitignoreFileContent)) {
            return $this->gitignoreFilesCache[$path] = null;
        }

        return $this->gitignoreFilesCache[$path] = [
            Gitignore::toRegex($gitignoreFileContent),
            Gitignore::toRegexMatchingNegatedPatterns($gitignoreFileContent),
        ];
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (is_file($path) || is_dir(rtrim($path, '/'))) {
            $realPath = realpath(rtrim($path, '/'));

            if (is_string($realPath) && $realPath !== '') {
                return str_replace('\\', '/', $realPath) . (str_ends_with($path, '/') ? '/' : '');
            }
        }

        return $path;
    }
}
