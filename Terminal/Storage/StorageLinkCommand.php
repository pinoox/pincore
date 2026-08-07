<?php

namespace Pinoox\Terminal\Storage;

use Pinoox\Component\Terminal;
use Pinoox\Portal\Config;
use Pinoox\Support\SystemConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'storage:link',
    description: 'Create the symbolic links configured for public storage (Laravel-style)',
    aliases: ['storage:unlink'],
)]
class StorageLinkCommand extends Terminal
{
    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Create or remove public storage links from filesystems.links config.

Examples:
  php pinoox storage:link
  php pinoox storage:unlink
HELP
            )
            ->addOption('relative', 'r', InputOption::VALUE_NONE, 'Create relative symlinks when possible');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $unlink = $this->invokedName() === 'storage:unlink';
        $links = $this->configuredLinks();

        if ($links === []) {
            $io->warning('No links configured in filesystems.links.');

            return Command::SUCCESS;
        }

        $relative = (bool) $input->getOption('relative');
        $ok = true;

        foreach ($links as $link => $target) {
            $linkPath = $this->absolutePath($link);
            $targetPath = $this->absolutePath($target);

            if ($unlink) {
                if (is_link($linkPath) || $this->isWindowsJunction($linkPath)) {
                    if (@unlink($linkPath) || $this->removeWindowsJunction($linkPath)) {
                        $io->writeln('<info>Removed:</info> ' . $linkPath);
                    } else {
                        $io->error('Failed to remove: ' . $linkPath);
                        $ok = false;
                    }
                } elseif (file_exists($linkPath)) {
                    $io->warning('Not a symlink, skipped: ' . $linkPath);
                } else {
                    $io->writeln('Missing (ok): ' . $linkPath);
                }
                continue;
            }

            if (!is_dir($targetPath) && !@mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
                $io->error('Target missing and could not be created: ' . $targetPath);
                $ok = false;
                continue;
            }

            if (file_exists($linkPath) || is_link($linkPath)) {
                if (is_link($linkPath) || $this->isWindowsJunction($linkPath)) {
                    $io->writeln('Exists: ' . $linkPath);
                    continue;
                }
                $io->error('Link path already exists and is not a symlink: ' . $linkPath);
                $ok = false;
                continue;
            }

            $linkParent = dirname($linkPath);
            if (!is_dir($linkParent) && !@mkdir($linkParent, 0755, true) && !is_dir($linkParent)) {
                $io->error('Cannot create parent for link: ' . $linkParent);
                $ok = false;
                continue;
            }

            $to = $relative ? $this->relativeTarget($linkPath, $targetPath) : $targetPath;

            if ($this->createLink($linkPath, $to, $targetPath)) {
                $io->writeln('<info>Linked:</info> ' . $linkPath . ' → ' . $targetPath);
            } else {
                $io->error('Failed to link: ' . $linkPath . ' → ' . $targetPath);
                $ok = false;
            }
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    private function invokedName(): string
    {
        $argv = $_SERVER['argv'] ?? [];
        foreach ($argv as $arg) {
            if (is_string($arg) && str_starts_with($arg, 'storage:')) {
                return $arg;
            }
        }

        return (string) $this->getName();
    }

    /**
     * @return array<string, string>
     */
    private function configuredLinks(): array
    {
        $links = Config::name('~filesystems')->get('links');
        if (!is_array($links)) {
            return [];
        }

        $out = [];
        foreach ($links as $link => $target) {
            if (is_string($link) && is_string($target) && $link !== '' && $target !== '') {
                $out[$link] = $target;
            }
        }

        return $out;
    }

    private function absolutePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (str_starts_with($path, '~')) {
            return str_replace('\\', '/', SystemConfig::resolvePath($path));
        }

        if (preg_match('#^[A-Za-z]:/#', $path) || str_starts_with($path, '/')) {
            return $path;
        }

        $base = str_replace('\\', '/', SystemConfig::path('base') ?: getcwd());

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    private function relativeTarget(string $linkPath, string $targetPath): string
    {
        try {
            $linkDir = dirname($linkPath);
            $relative = $this->relativePath($linkDir, $targetPath);

            return $relative !== '' ? $relative : $targetPath;
        } catch (\Throwable) {
            return $targetPath;
        }
    }

    private function relativePath(string $from, string $to): string
    {
        $from = explode('/', trim(str_replace('\\', '/', $from), '/'));
        $to = explode('/', trim(str_replace('\\', '/', $to), '/'));

        while ($from && $to && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return str_repeat('../', count($from)) . implode('/', $to);
    }

    private function createLink(string $linkPath, string $targetForLink, string $absoluteTarget): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Junction/directory symlink on Windows
            $cmd = sprintf('cmd /c mklink /J %s %s', escapeshellarg($linkPath), escapeshellarg($absoluteTarget));
            exec($cmd, $out, $code);

            return $code === 0 && (is_link($linkPath) || is_dir($linkPath));
        }

        return @symlink($targetForLink, $linkPath);
    }

    private function isWindowsJunction(string $path): bool
    {
        if (PHP_OS_FAMILY !== 'Windows' || !file_exists($path)) {
            return false;
        }

        // Best-effort: directory that isn't a real dir listing via readlink
        return is_dir($path) && @readlink($path) !== false;
    }

    private function removeWindowsJunction(string $path): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }

        exec(sprintf('cmd /c rmdir %s', escapeshellarg($path)), $out, $code);

        return $code === 0 && !file_exists($path);
    }
}
