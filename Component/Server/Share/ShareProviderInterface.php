<?php

namespace Pinoox\Component\Server\Share;

interface ShareProviderInterface
{
    public function id(): string;

    public function label(): string;

    public function hint(): string;

    /**
     * Short label for menu: none, optional, required.
     */
    public function signupLabel(): string;

    /**
     * Multi-line prerequisites, .env keys, and troubleshooting.
     */
    public function connectionGuide(): string;

    public function setupLevel(): ShareSetupLevel;

    public function setupGuide(): string;

    /**
     * Prepare tools/binaries. Returns true when start() can run.
     */
    public function ensureReady(): bool;

    /**
     * True when ensureReady() would succeed without user action.
     */
    public function isReady(): bool;

    /**
     * Whether auto mode may attempt this provider (includes auto-install binaries).
     */
    public function canAutoTry(): bool;

    /**
     * @return 'binary'|'ssh'|'npx'|'ngrok'
     */
    public function transportKind(): string;

    /**
     * Higher = preferred in auto mode when reachable.
     */
    public function autoPriority(): int;

    public function isInstalled(): bool;

    public function probe(int $timeoutSeconds = 3): bool;

    public function lastProbeLatencyMs(): int;

    public function start(int $port): ?string;

    public function stop(): void;

    public function isRunning(): bool;

    public function hasDisconnected(): bool;

    public function getPublicUrl(): ?string;
}
