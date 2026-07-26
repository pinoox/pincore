<?php

namespace Pinoox\Component\Server\Share;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

abstract class AbstractBinaryShareProvider extends AbstractShareProvider
{
    abstract protected function binaryFileName(): string;

    abstract protected function downloadUrl(): ?string;

    protected function needsArchiveExtract(): bool
    {
        return false;
    }

    protected function archiveExtractedBinaryName(): string
    {
        return $this->binaryFileName();
    }

    public function setupLevel(): ShareSetupLevel
    {
        return $this->resolveBinary() !== null ? ShareSetupLevel::Ready : ShareSetupLevel::AutoInstall;
    }

    public function isInstalled(): bool
    {
        return $this->resolveBinary() !== null;
    }

    protected function autoInstall(): bool
    {
        $local = $this->localBinaryPath();
        $this->output->writeln('<comment>Share: ' . $this->label() . ' not found — downloading…</comment>');

        return $this->downloadBinary($local);
    }

    protected function resolveBinary(): ?string
    {
        $system = ShareToolkit::findInPath($this->binaryFileName());

        if ($system !== null) {
            return $system;
        }

        $local = $this->localBinaryPath();

        if (is_file($local)) {
            return $local;
        }

        return null;
    }

    protected function localBinaryPath(): string
    {
        return ShareToolkit::binDir($this->projectRoot) . DIRECTORY_SEPARATOR . $this->binaryFileName();
    }

    protected function buildCommand(int $port): array
    {
        $binary = $this->resolveBinary();

        if ($binary === null) {
            $this->output->writeln('<error>Share: could not obtain ' . $this->label() . ' binary.</error>');

            return [];
        }

        return $this->buildBinaryCommand($binary, $port);
    }

    /**
     * @return list<string>
     */
    abstract protected function buildBinaryCommand(string $binary, int $port): array;

    private function downloadBinary(string $dest): bool
    {
        $url = $this->downloadUrl();

        if ($url === null) {
            $this->output->writeln('<error>Share: unsupported OS/architecture for automatic download.</error>');

            return false;
        }

        $this->output->writeln('<fg=gray>  → ' . $url . '</>');

        $downloadDest = $this->needsArchiveExtract() ? $dest . '.archive' : $dest;

        if (!ShareToolkit::downloadFile($url, $downloadDest, $this->output)) {
            return false;
        }

        if ($this->needsArchiveExtract()) {
            $extracted = $this->extractArchive($downloadDest, dirname($dest));

            if ($extracted === null) {
                return false;
            }

            @unlink($downloadDest);
            $dest = $extracted;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($dest, 0755);
        }

        ShareToolkit::unblockWindowsBinary($dest);

        if (!ShareToolkit::probeBinaryHelp($dest)) {
            $this->output->writeln('<error>Share: ' . $this->label() . ' binary downloaded but cannot run on this system.</error>');

            if (PHP_OS_FAMILY === 'Windows') {
                $this->output->writeln('<comment>  Windows may have blocked the file (Defender/SmartScreen).</comment>');
                $this->output->writeln('<comment>  Allow .pinoox/bin/' . basename($dest) . ' or install bore manually: winget install ekzhang.bore</comment>');
            }

            @unlink($dest);

            return false;
        }

        $this->output->writeln('<info>Share: ' . $this->label() . ' downloaded to ' . $dest . '</info>');

        return is_file($dest);
    }

    private function extractArchive(string $archive, string $dir): ?string
    {
        $name = $this->archiveExtractedBinaryName();

        if (str_ends_with(strtolower($archive), '.zip') && PHP_OS_FAMILY === 'Windows') {
            $process = new Process([
                'powershell',
                '-NoProfile',
                '-Command',
                'Expand-Archive -Path ' . escapeshellarg($archive) . ' -DestinationPath ' . escapeshellarg($dir) . ' -Force',
            ]);
            $process->setTimeout(60);
            $process->run();
        } else {
            $flag = str_ends_with(strtolower($archive), '.zip') ? '-xf' : '-xzf';
            $process = new Process(['tar', $flag, $archive, '-C', $dir]);
            $process->setTimeout(60);
            $process->run();
        }

        $candidate = $dir . DIRECTORY_SEPARATOR . $name;

        if (is_file($candidate)) {
            return $candidate;
        }

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file) && basename($file) === $name) {
                return $file;
            }
        }

        $nested = $this->findBinaryInTree($dir, $name);

        if ($nested !== null) {
            $target = $dir . DIRECTORY_SEPARATOR . $name;

            if ($nested !== $target && !@rename($nested, $target)) {
                return $nested;
            }

            return is_file($target) ? $target : $nested;
        }

        $this->output->writeln('<error>Share: failed to extract ' . $this->label() . ' archive.</error>');

        return null;
    }

    private function findBinaryInTree(string $dir, string $name): ?string
    {
        if (!is_dir($dir)) {
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $name) {
                return $file->getPathname();
            }
        }

        return null;
    }
}
