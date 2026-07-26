<?php

namespace Pinoox\Component\Server\Share;

abstract class AbstractSshShareProvider extends AbstractShareProvider
{
    public function transportKind(): string
    {
        return 'ssh';
    }

    public function setupLevel(): ShareSetupLevel
    {
        return ShareToolkit::findSsh() !== null ? ShareSetupLevel::Ready : ShareSetupLevel::NeedsTool;
    }

    public function isInstalled(): bool
    {
        return ShareToolkit::findSsh() !== null;
    }

    public function setupGuide(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return implode("\n", [
                'OpenSSH client is required.',
                'Settings → Apps → Optional features → Add "OpenSSH Client".',
                'Or run in PowerShell (Admin): Add-WindowsCapability -Online -Name OpenSSH.Client~~~~0.0.1.0',
            ]);
        }

        return implode("\n", [
            'Install OpenSSH client (usually: openssh-client package).',
            'macOS/Linux: ssh should already be available in Terminal.',
        ]);
    }

    /**
     * @return list<string>
     */
    abstract protected function sshArgs(int $port): array;

    protected function buildCommand(int $port): array
    {
        $ssh = ShareToolkit::findSsh();

        if ($ssh === null) {
            return [];
        }

        return array_merge([$ssh], $this->sshArgs($port));
    }
}
