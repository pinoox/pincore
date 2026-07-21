<?php

namespace Pinoox\Component\Server\Share;

abstract class AbstractNpxShareProvider extends AbstractShareProvider
{
    abstract protected function npmPackage(): string;

    /**
     * @return list<string>
     */
    abstract protected function npxArgs(int $port): array;

    public function transportKind(): string
    {
        return 'npx';
    }

    public function setupLevel(): ShareSetupLevel
    {
        return ShareToolkit::findNpx() !== null ? ShareSetupLevel::Ready : ShareSetupLevel::NeedsTool;
    }

    public function isInstalled(): bool
    {
        return ShareToolkit::findNpx() !== null;
    }

    public function setupGuide(): string
    {
        return implode("\n", [
            'Node.js and npx are required (no account needed for first run).',
            'Install Node.js LTS: https://nodejs.org/',
            'First run may download the package — allow network access.',
        ]);
    }

    protected function buildCommand(int $port): array
    {
        $npx = ShareToolkit::findNpx();

        if ($npx === null) {
            return [];
        }

        return array_merge([$npx, '--yes', $this->npmPackage()], $this->npxArgs($port));
    }
}
