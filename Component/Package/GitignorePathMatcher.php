<?php

namespace Pinoox\Component\Package;

use Symfony\Component\Finder\Gitignore;
use Symfony\Component\Process\Process;

/**
 * Match paths against nested .gitignore rules using git check-ignore when available.
 */
final class GitignorePathMatcher
{
    private string $baseDir;

    private ?string $gitRoot = null;

    private ?bool $gitBinaryAvailable = null;

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
                $this->gitRoot = $directory;
                break;
            }
        }
    }

    /**
     * When true, build selectors should rely on git check-ignore instead of Finder::ignoreVCSIgnored().
     */
    public function usesGitEngine(): bool
    {
        return $this->gitRoot !== null && $this->isGitBinaryAvailable();
    }

    /**
     * Symfony Finder gitignore parsing is only used when git is unavailable or outside a repository.
     */
    public function shouldUseFinderGitignore(): bool
    {
        return !$this->usesGitEngine();
    }

    public function isIgnored(string $path): bool
    {
        $path = $this->normalizePath($path);

        if (!str_starts_with($path, $this->baseDir)) {
            $path = $this->baseDir . '/' . ltrim($path, '/');
        }

        if ($this->usesGitEngine()) {
            $gitIgnored = $this->isIgnoredByGit($path);

            if ($gitIgnored !== null) {
                return $gitIgnored;
            }
        }

        if (is_dir($path) && !str_ends_with($path, '/')) {
            $path .= '/';
        }

        return $this->matchesIgnored($path);
    }

    /**
     * @param list<string> $absolutePaths
     * @return list<string>
     */
    public function filterIncludedPaths(array $absolutePaths): array
    {
        if ($absolutePaths === []) {
            return [];
        }

        $normalizedByOriginal = [];

        foreach ($absolutePaths as $path) {
            $normalizedByOriginal[$path] = $this->normalizePath($path);
        }

        if ($this->usesGitEngine()) {
            $ignored = $this->gitIgnoredPathsFromStdin(array_values($normalizedByOriginal));

            if ($ignored !== null) {
                $ignoredLookup = array_fill_keys(
                    array_map(fn (string $ignoredPath): string => $this->normalizePath($ignoredPath), $ignored),
                    true,
                );

                return array_values(array_filter(
                    $absolutePaths,
                    static fn (string $path): bool => !isset($ignoredLookup[$normalizedByOriginal[$path]]),
                ));
            }
        }

        return array_values(array_filter(
            $absolutePaths,
            fn (string $path): bool => !$this->isIgnored($path),
        ));
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

    private function isIgnoredByGit(string $absolutePath): ?bool
    {
        if ($this->gitRoot === null) {
            return null;
        }

        $process = new Process(
            ['git', '-C', $this->gitRoot, 'check-ignore', '-q', $this->normalizePath($absolutePath)],
        );
        $process->run();

        return match ($process->getExitCode()) {
            0 => true,
            1 => false,
            default => null,
        };
    }

    /**
     * @param list<string> $absolutePaths
     * @return list<string>|null
     */
    private function gitIgnoredPathsFromStdin(array $absolutePaths): ?array
    {
        if ($this->gitRoot === null || $absolutePaths === []) {
            return [];
        }

        $process = new Process(['git', '-C', $this->gitRoot, 'check-ignore', '--stdin', '-z']);
        $process->setInput(implode("\0", $absolutePaths) . "\0");
        $process->run();

        if (!in_array($process->getExitCode(), [0, 1], true)) {
            return null;
        }

        $output = trim($process->getOutput());

        if ($output === '') {
            return [];
        }

        return array_values(array_filter(explode("\0", $output)));
    }

    private function isGitBinaryAvailable(): bool
    {
        if ($this->gitBinaryAvailable !== null) {
            return $this->gitBinaryAvailable;
        }

        $process = new Process(['git', '--version']);
        $process->run();

        return $this->gitBinaryAvailable = $process->isSuccessful();
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
