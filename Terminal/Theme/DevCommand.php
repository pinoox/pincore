<?php

namespace Pinoox\Terminal\Theme;

use Pinoox\Component\Template\Frontend\FrontendConfig;
use Pinoox\Component\Template\Frontend\ThemeFrontend;
use Pinoox\Component\Terminal;
use Pinoox\Support\DevApp;
use Pinoox\Support\ProjectCli;
use Pinoox\Portal\App\AppEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'dev',
    description: 'Start PHP + Vite dev with HMR for an app theme (shortcut for fe dev)',
)]
class DevCommand extends Terminal
{
    protected function configure(): void
    {
        $serve = ProjectCli::platformFormat('serve');
        $this
            ->setHelp($this->cliHelp(
                "One-command local development: starts {$serve} + Vite HMR for a theme.\n\n"
                . "Waits until Vite is ready, then prints the PHP URL to open (not the Vite port).\n\n"
                . 'Same as: ' . $this->cliFormat('fe {target} dev'),
                [
                    'dev',
                    'dev com_pinoox_manager',
                    'dev platform',
                    'dev spark',
                    'dev spark --domain=pinoox.test',
                    'dev spark --no-serve',
                    'dev com_pinoox_manager --network',
                    'dev spark --fix-vite --install',
                    'dev spark --share',
                    'dev spark -N --share',
                    'dev spark --share --share-password=123456',
                    'dev spark --share --share-expire=2h',
                ],
                "Use MAMP or another PHP server:\n  " . $this->cliFormat('dev spark --no-serve')
                . "\n\nLocal domain (add to hosts: 127.0.0.1 pinoox.test):\n  " . $this->cliFormat('dev spark --domain=pinoox.test')
                . "\n\nLAN (phone/tablet on same Wi‑Fi):\n  " . $this->cliFormat('dev spark --network')
                . "\n\nSingle-app dev mounts at / (package@/). For the platform router use "
                . $this->cliFormat('dev platform') . ' or ' . $this->cliFormat('fe dev:apps') . ".\n\n"
                . "Built manifest assets only: use {$serve} — not dev.",
            ))
            ->addArgument('target', InputArgument::OPTIONAL, 'App package or theme folder (interactive when omitted)')
            ->addOption('theme', null, InputOption::VALUE_REQUIRED, 'Theme folder when target is a package')
            ->addOption('install', null, InputOption::VALUE_NONE, 'Run npm install when needed')
            ->addOption('no-install', null, InputOption::VALUE_NONE, 'Skip npm install')
            ->addOption('no-serve', null, InputOption::VALUE_NONE, 'Do not start ' . $serve . ' (MAMP, Docker, etc.)')
            ->addOption('serve-app', null, InputOption::VALUE_REQUIRED, 'App binding for ' . $serve . ' (package@path or platform)')
            ->addOption('serve-host', null, InputOption::VALUE_REQUIRED, 'Host for ' . $serve)
            ->addOption('serve-port', null, InputOption::VALUE_REQUIRED, 'Port for ' . $serve)
            ->addOption('serve-domain', null, InputOption::VALUE_REQUIRED, 'Local hostname for browser URLs (default from SERVER_DOMAIN)')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Alias for --serve-domain')
            ->addOption('no-fix-hosts', null, InputOption::VALUE_NONE, 'Do not auto-update the system hosts file for --domain')
            ->addOption('network', 'N', InputOption::VALUE_NONE, 'Serve PHP app + Vite on LAN (same Wi‑Fi)')
            ->addOption('vite-host', null, InputOption::VALUE_REQUIRED, 'Vite bind host (default 127.0.0.1)')
            ->addOption('vite-network', null, InputOption::VALUE_NONE, 'Bind Vite to 0.0.0.0 for LAN access')
            ->addOption('verbose-vite', null, InputOption::VALUE_NONE, 'Show full Vite startup URLs')
            ->addOption('fix-vite', null, InputOption::VALUE_NONE, 'Auto-wire vite.config.js with pinooxDevState/pinooxServer when missing')
            ->addOption('env-file', null, InputOption::VALUE_REQUIRED, 'Theme env file for dev auto-setup (default: .env)')
            ->addOption('no-inspector', null, InputOption::VALUE_NONE, 'Disable Pinx Inspector on /~inspector')
            ->addOption('open-inspector', null, InputOption::VALUE_NONE, 'Open Pinx Inspector in the browser')
            ->addOption('share', null, InputOption::VALUE_NONE, 'Expose the server via a public tunnel (Cloudflare, Pinggy, ngrok, …)')
            ->addOption('share-provider', null, InputOption::VALUE_OPTIONAL, 'Tunnel provider: auto, pinggy, bore, cloudflare, serveo, localhostrun, tunnelmole, ngrok, localtunnel')
            ->addOption('share-password', null, InputOption::VALUE_OPTIONAL, 'Protect the share URL with a password')
            ->addOption('share-expire', null, InputOption::VALUE_OPTIONAL, 'Auto-stop the tunnel after a duration (e.g. 2h, 30m, 60s)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $target = trim((string) $input->getArgument('target'));

        if ($target === '') {
            $default = DevApp::defaultCliPackage();
            if ($default !== 'platform'
                && AppEngine::exists($default)
                && ThemeFrontend::listThemeFolders($default) !== []) {
                $target = $default;
            }
        }

        if ($target === '') {
            $io->title('Pinoox dev');
            $io->writeln('Starting interactive theme frontend dev (fe dev)…');
        }

        $this->activateViteHmrMode();

        $command = $this->getApplication()?->find('theme:frontend');

        if ($command === null) {
            $io->error('theme:frontend command is not registered.');

            return Command::FAILURE;
        }

        $arguments = array_filter([
            'command' => 'theme:frontend',
            'target' => $target !== '' ? $target : null,
            'action' => 'dev',
            '--theme' => $input->getOption('theme'),
            '--no-serve' => $input->getOption('no-serve') ? true : null,
            '--install' => $input->getOption('install') ? true : null,
            '--no-install' => $input->getOption('no-install') ? true : null,
            '--serve-app' => $input->getOption('serve-app'),
            '--serve-host' => $input->getOption('serve-host'),
            '--serve-port' => $input->getOption('serve-port'),
            '--serve-domain' => $input->getOption('serve-domain'),
            '--domain' => $input->getOption('domain'),
            '--no-fix-hosts' => $input->getOption('no-fix-hosts') ? true : null,
            '--network' => $input->getOption('network') ? true : null,
            '--vite-host' => $input->getOption('vite-host'),
            '--vite-network' => $input->getOption('vite-network') ? true : null,
            '--verbose-vite' => $input->getOption('verbose-vite') ? true : null,
            '--fix-vite' => $input->getOption('fix-vite') ? true : null,
            '--env-file' => $input->getOption('env-file'),
            '--no-inspector' => $input->getOption('no-inspector') ? true : null,
            '--open-inspector' => $input->getOption('open-inspector') ? true : null,
            '--share' => $input->getOption('share') ? true : null,
            '--share-provider' => $input->getOption('share-provider') ?: null,
            '--share-password' => $input->getOption('share-password') ?: null,
            '--share-expire' => $input->getOption('share-expire') ?: null,
        ], static fn ($value) => $value !== null && $value !== false && $value !== '');

        return $command->run(new ArrayInput($arguments), $output);
    }

    private function activateViteHmrMode(): void
    {
        putenv(FrontendConfig::VITE_HMR_ENV . '=1');
        $_ENV[FrontendConfig::VITE_HMR_ENV] = '1';
        $_SERVER[FrontendConfig::VITE_HMR_ENV] = '1';
    }
}
