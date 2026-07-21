<?php

namespace Pinoox\Component\Server\Share\Providers;

use Pinoox\Component\Server\Share\AbstractShareProvider;
use Pinoox\Component\Server\Share\ShareSetupLevel;
use Pinoox\Component\Server\Share\ShareToolkit;

class NgrokShareProvider extends AbstractShareProvider
{
    public function id(): string
    {
        return 'ngrok';
    }

    public function label(): string
    {
        return 'ngrok';
    }

    public function hint(): string
    {
        return 'Free account + authtoken required';
    }

    public function signupLabel(): string
    {
        return 'required';
    }

    public function connectionGuide(): string
    {
        return implode("\n", [
            'Prerequisites: ngrok CLI — https://ngrok.com/download (Windows: winget install ngrok.ngrok).',
            'Signup: required — free account at https://dashboard.ngrok.com/signup',
            '▸ Copy authtoken: https://dashboard.ngrok.com/get-started/your-authtoken',
            '▸ Run once: ngrok config add-authtoken YOUR_TOKEN',
            '▸ Or set env: NGROK_AUTHTOKEN=your_token',
            '▸ Run: php pinoox serve --share --share-provider=ngrok',
            '▸ Requires ngrok.com reachable from your network and a valid authtoken.',
            'If it fails: run `ngrok config check` or try pinggy / bore without signup.',
        ]);
    }

    public function transportKind(): string
    {
        return 'ngrok';
    }

    public function autoPriority(): int
    {
        return 30;
    }

    public function setupLevel(): ShareSetupLevel
    {
        if (ShareToolkit::findInPath(PHP_OS_FAMILY === 'Windows' ? 'ngrok.exe' : 'ngrok') === null) {
            return ShareSetupLevel::NeedsTool;
        }

        return ShareToolkit::ngrokAuthtokenConfigured() ? ShareSetupLevel::Ready : ShareSetupLevel::NeedsAccount;
    }

    public function isInstalled(): bool
    {
        return ShareToolkit::findInPath(PHP_OS_FAMILY === 'Windows' ? 'ngrok.exe' : 'ngrok') !== null;
    }

    public function setupGuide(): string
    {
        if ($this->setupLevel() === ShareSetupLevel::NeedsTool) {
            return implode("\n", [
                'Install ngrok: https://ngrok.com/download',
                'Or: winget install ngrok.ngrok (Windows)',
            ]);
        }

        return implode("\n", [
            '1. Sign up free: https://dashboard.ngrok.com/signup',
            '2. Copy your authtoken from: https://dashboard.ngrok.com/get-started/your-authtoken',
            '3. Run: ngrok config add-authtoken YOUR_TOKEN',
            '4. Retry: php pinoox serve --share --share-provider=ngrok',
        ]);
    }

    protected function performProbe(int $timeoutSeconds): bool
    {
        if (!$this->isReady()) {
            return false;
        }

        return ShareToolkit::canReachHttps('https://ngrok.com', $timeoutSeconds);
    }

    protected function buildCommand(int $port): array
    {
        $binary = ShareToolkit::findInPath(PHP_OS_FAMILY === 'Windows' ? 'ngrok.exe' : 'ngrok');

        if ($binary === null) {
            return [];
        }

        return [
            $binary,
            'http',
            (string) $port,
            '--log=stdout',
            '--log-format=logfmt',
        ];
    }

    protected function urlPatterns(): array
    {
        return [
            '/url=(https:\/\/[a-z0-9\-]+\.ngrok-free\.app)/i',
            '/url=(https:\/\/[a-z0-9\-]+\.ngrok\.io)/i',
            '/(https:\/\/[a-z0-9\-]+\.ngrok-free\.app)/i',
            '/(https:\/\/[a-z0-9\-]+\.ngrok\.io)/i',
        ];
    }
}
