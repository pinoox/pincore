<?php

namespace Pinoox\Component\Server\Share;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

abstract class AbstractShareProvider implements ShareProviderInterface
{
    protected ?Process $process = null;

    protected ?string $publicUrl = null;

    protected int $lastProbeLatencyMs = 0;

    public function __construct(
        protected readonly string $projectRoot,
        protected readonly OutputInterface $output,
    ) {
    }

    public function signupLabel(): string
    {
        return match ($this->setupLevel()) {
            ShareSetupLevel::NeedsAccount => 'required',
            default => 'none',
        };
    }

    public function connectionGuide(): string
    {
        return $this->setupGuide();
    }

    public function setupLevel(): ShareSetupLevel
    {
        return $this->isInstalled() ? ShareSetupLevel::Ready : ShareSetupLevel::NeedsTool;
    }

    public function setupGuide(): string
    {
        return 'Install the required tool and run share again.';
    }

    public function isReady(): bool
    {
        return match ($this->setupLevel()) {
            ShareSetupLevel::Ready => true,
            ShareSetupLevel::AutoInstall => $this->isInstalled(),
            default => false,
        };
    }

    public function canAutoTry(): bool
    {
        return match ($this->setupLevel()) {
            ShareSetupLevel::Ready, ShareSetupLevel::AutoInstall => true,
            default => false,
        };
    }

    public function ensureReady(): bool
    {
        if ($this->isReady()) {
            return true;
        }

        if ($this->setupLevel() === ShareSetupLevel::AutoInstall) {
            return $this->autoInstall();
        }

        $this->emitSetupGuide();

        return false;
    }

    public function transportKind(): string
    {
        return 'binary';
    }

    public function lastProbeLatencyMs(): int
    {
        return $this->lastProbeLatencyMs;
    }

    public function probe(int $timeoutSeconds = 3): bool
    {
        $started = microtime(true);
        $result = $this->performProbe($timeoutSeconds);
        $this->lastProbeLatencyMs = (int) round((microtime(true) - $started) * 1000);

        return $result;
    }

    protected function performProbe(int $timeoutSeconds): bool
    {
        return false;
    }

    protected function autoInstall(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    abstract protected function buildCommand(int $port): array;

    /**
     * @return list<string>
     */
    abstract protected function urlPatterns(): array;

    /**
     * @return list<string>
     */
    protected function readyMarkers(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    protected function connectionErrorMarkers(): array
    {
        return [
            'context deadline exceeded',
            'connection refused',
            'actively refused',
            'Permission denied',
            'Could not resolve hostname',
            'No such file or directory',
            'Connection timed out',
            'Connection reset',
            'command not found',
            'not recognized as an internal or external command',
            'ERR_NGROK',
            'authentication failed',
        ];
    }

    protected function waitSeconds(): int
    {
        return 45;
    }

    protected function urlGraceSeconds(): int
    {
        return 20;
    }

    public function start(int $port): ?string
    {
        if (!$this->ensureReady()) {
            return null;
        }

        $command = $this->buildCommand($port);

        if ($command === []) {
            return null;
        }

        $this->process = new Process($command, $this->projectRoot);
        $this->process->setTimeout(null);

        $urlBuffer = '';
        $this->process->start(function (string $type, string $chunk) use (&$urlBuffer): void {
            $urlBuffer .= $chunk;
        });

        $deadline = microtime(true) + $this->waitSeconds();
        $urlFoundAt = null;
        $readyMarkers = $this->readyMarkers();

        while (microtime(true) < $deadline && $this->process->isRunning()) {
            $url = $this->extractPublicUrl($urlBuffer);

            if ($url !== null && $urlFoundAt === null) {
                $urlFoundAt = microtime(true);
                $this->publicUrl = $url;
            }

            if ($url !== null && ($readyMarkers === [] || ShareToolkit::bufferHasAny($urlBuffer, $readyMarkers))) {
                $this->publicUrl = $url;

                return $url;
            }

            if (ShareToolkit::bufferHasAny($urlBuffer, $this->connectionErrorMarkers())) {
                $this->emitConnectionError($urlBuffer);
                $this->process->stop(3);

                return null;
            }

            if ($urlFoundAt !== null && (microtime(true) - $urlFoundAt) > $this->urlGraceSeconds()) {
                break;
            }

            usleep(300_000);
        }

        $url = $this->publicUrl ?? $this->extractPublicUrl($urlBuffer);

        if ($url !== null) {
            if ($this->process->isRunning() && $readyMarkers !== [] && !ShareToolkit::bufferHasAny($urlBuffer, $readyMarkers)) {
                $this->output->writeln('<comment>Share: tunnel URL created but the edge connection is still establishing…</comment>');
            }

            $this->publicUrl = $url;

            return $url;
        }

        if (!$this->process->isRunning()) {
            $this->emitStartFailure($urlBuffer);
        } else {
            $this->output->writeln('<comment>Share: ' . $this->label() . ' started but URL not detected yet.</comment>');
        }

        return null;
    }

    public function stop(): void
    {
        if ($this->process !== null && $this->process->isRunning()) {
            $this->process->stop(3);
        }
    }

    public function isRunning(): bool
    {
        return $this->process !== null && $this->process->isRunning();
    }

    public function hasDisconnected(): bool
    {
        return $this->publicUrl !== null && !$this->isRunning();
    }

    public function getPublicUrl(): ?string
    {
        return $this->publicUrl;
    }

    protected function extractPublicUrl(string $buffer): ?string
    {
        return ShareToolkit::extractUrl($buffer, $this->urlPatterns());
    }

    protected function emitSetupGuide(): void
    {
        ShareGuideRenderer::print($this->output, $this);
    }

    protected function emitConnectionError(string $buffer): void
    {
        $this->output->writeln('<error>Share: ' . $this->label() . ' could not connect.</error>');
        ShareGuideRenderer::print($this->output, $this);
        $this->writeTail($buffer);
    }

    protected function emitStartFailure(string $buffer): void
    {
        if ($this->process !== null) {
            $buffer .= $this->process->getOutput() . $this->process->getErrorOutput();
        }

        $this->output->writeln('<error>Share: ' . $this->label() . ' exited without a public URL.</error>');
        ShareGuideRenderer::print($this->output, $this);
        $this->writeTail($buffer);
    }

    protected function writeTail(string $buffer): void
    {
        $trimmed = trim(strip_tags($buffer));

        if ($trimmed === '') {
            return;
        }

        foreach (array_slice(explode("\n", $trimmed), -3) as $line) {
            $line = trim($line);

            if ($line !== '') {
                $this->output->writeln('  <fg=gray>' . $line . '</>');
            }
        }
    }
}
