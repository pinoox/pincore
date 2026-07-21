<?php

namespace Pinoox\Terminal\Share;

use Pinoox\Component\Server\Share\ShareGuideRenderer;
use Pinoox\Component\Server\Share\ShareProviderRegistry;
use Pinoox\Component\Server\Share\ShareProviderSelector;
use Pinoox\Component\Server\Share\ShareWizard;
use Pinoox\Component\Server\Share\ShareWizardResult;
use Pinoox\Component\Template\Frontend\FrontendConfig;
use Pinoox\Component\Terminal;
use Pinoox\Support\ProjectCli;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'share',
    description: 'Interactive wizard to expose your local server via a public tunnel',
)]
class ShareCommand extends Terminal
{
    protected function configure(): void
    {
        $serve = ProjectCli::platformFormat('serve');
        $dev = ProjectCli::platformFormat('dev');

        $this
            ->setHelp($this->cliHelp(
                "Step-by-step wizard: pick a tunnel provider, then start {$serve} or {$dev} with --share.\n\n"
                . "Default provider order in auto mode:\n"
                . "  auto → pinggy → serveo → cloudflare → localhostrun → bore → tunnelmole → ngrok → localtunnel",
                [
                    'share',
                    'share --provider=auto --mode=serve',
                    'share --share-provider=pinggy --mode=dev',
                    'share --mode=guide --provider=pinggy',
                    'share --network --share-password=secret',
                    'share --share-expire=2h',
                ],
                'Non-interactive: pass --provider and --mode. Omit --mode to default to serve.',
            ))
            ->addOption('provider', null, InputOption::VALUE_OPTIONAL, 'Tunnel provider (alias for --share-provider)')
            ->addOption('share-provider', null, InputOption::VALUE_OPTIONAL, 'Tunnel provider: auto, pinggy, serveo, cloudflare, localhostrun, bore, tunnelmole, ngrok, localtunnel')
            ->addOption('mode', null, InputOption::VALUE_OPTIONAL, 'Mode: serve, dev, or guide')
            ->addOption('network', 'N', InputOption::VALUE_NONE, 'Listen on LAN (0.0.0.0)')
            ->addOption('app', null, InputOption::VALUE_REQUIRED, 'Lock to one app (package, route path, alias, or package@path)')
            ->addOption('target', null, InputOption::VALUE_OPTIONAL, 'App package or theme for dev mode')
            ->addOption('share-password', null, InputOption::VALUE_OPTIONAL, 'Protect the share URL with a password')
            ->addOption('share-expire', null, InputOption::VALUE_OPTIONAL, 'Auto-stop the tunnel after a duration (e.g. 2h, 30m, 60s)')
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'Host address for serve/dev')
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Port number for serve/dev')
            ->addOption('domain', null, InputOption::VALUE_OPTIONAL, 'Local hostname for browser URLs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $projectRoot = rtrim(str_replace('\\', '/', (string) PINOOX_BASE_PATH), '/');
        $envProvider = (string) _env('SERVER_SHARE_PROVIDER', '');

        if (!$input->isInteractive() && !$this->hasExplicitProvider($input) && trim($envProvider) === '') {
            $io->writeln('<comment>Using provider auto (non-interactive default).</comment>');
        }

        $providerOption = $this->resolveProviderOption($input);
        $modeOption = $input->getOption('mode');
        $passwordOption = $input->getOption('share-password');
        $expireOption = $input->getOption('share-expire');
        $networkFromCli = (bool) $input->getOption('network');

        /** @var QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');

        $wizard = new ShareWizard();

        if ($input->isInteractive()) {
            $io->title('Pinoox share');
            $io->writeln('Expose your local server with a public tunnel URL.');
        }

        $result = $input->isInteractive()
            ? $wizard->run(
                $input,
                $output,
                $projectRoot,
                $questionHelper,
                $providerOption,
                is_string($modeOption) ? $modeOption : null,
                $envProvider,
                $networkFromCli,
                is_string($passwordOption) && trim($passwordOption) !== '' ? trim($passwordOption) : null,
                is_string($expireOption) && trim($expireOption) !== '' ? trim($expireOption) : null,
            )
            : new ShareWizardResult(
                provider: ShareProviderSelector::resolve($input, $output, $projectRoot, $providerOption, $envProvider),
                mode: $this->resolveModeFromInput($input),
                network: $networkFromCli,
                password: is_string($passwordOption) && trim($passwordOption) !== '' ? trim($passwordOption) : null,
                expire: is_string($expireOption) && trim($expireOption) !== '' ? trim($expireOption) : null,
                target: $this->optionalString($input->getOption('target')),
                app: $this->optionalString($input->getOption('app')),
            );

        if ($result->mode === ShareWizard::MODE_GUIDE) {
            return $this->renderGuide($output, $projectRoot, $result->provider);
        }

        if ($input->isInteractive()) {
            $io->writeln('');
            $io->writeln(sprintf(
                '<info>Starting %s with provider %s…</info>',
                $result->mode,
                $result->provider,
            ));
        }

        return $this->dispatch($input, $output, $result);
    }

    private function dispatch(InputInterface $input, OutputInterface $output, ShareWizardResult $result): int
    {
        $shareArgs = $this->shareOptionArgs($result);

        if ($result->mode === ShareWizard::MODE_DEV) {
            putenv(FrontendConfig::VITE_HMR_ENV . '=1');
            $_ENV[FrontendConfig::VITE_HMR_ENV] = '1';
            $_SERVER[FrontendConfig::VITE_HMR_ENV] = '1';

            $command = $this->getApplication()?->find('dev');

            if ($command === null) {
                $output->writeln('<error>dev command is not registered.</error>');

                return Command::FAILURE;
            }

            $arguments = array_filter([
                'command' => 'dev',
                'target' => $result->target,
                '--network' => $result->network ? true : null,
                '--serve-host' => $input->getOption('host'),
                '--serve-port' => $input->getOption('port'),
                '--serve-domain' => $input->getOption('domain'),
                '--domain' => $input->getOption('domain'),
                ...$shareArgs,
            ], static fn ($value) => $value !== null && $value !== false && $value !== '');

            return $command->run(new ArrayInput($arguments), $output);
        }

        $command = $this->getApplication()?->find('serve');

        if ($command === null) {
            $output->writeln('<error>serve command is not registered.</error>');

            return Command::FAILURE;
        }

        $arguments = array_filter([
            'command' => 'serve',
            '--app' => $result->app,
            '--network' => $result->network ? true : null,
            '--host' => $input->getOption('host'),
            '--port' => $input->getOption('port'),
            '--domain' => $input->getOption('domain'),
            ...$shareArgs,
        ], static fn ($value) => $value !== null && $value !== false && $value !== '');

        return $command->run(new ArrayInput($arguments), $output);
    }

    /**
     * @return array<string, bool|string>
     */
    private function shareOptionArgs(ShareWizardResult $result): array
    {
        return array_filter([
            '--share' => true,
            '--share-provider' => $result->provider,
            '--share-password' => $result->password,
            '--share-expire' => $result->expire,
        ], static fn ($value) => $value !== null && $value !== false && $value !== '');
    }

    private function renderGuide(OutputInterface $output, string $projectRoot, string $provider): int
    {
        $registry = new ShareProviderRegistry($projectRoot, $output);

        if ($provider === ShareProviderSelector::AUTO) {
            ShareGuideRenderer::printCatalog($output, $registry);
        } else {
            ShareGuideRenderer::print($output, $registry->get($provider));
        }

        return Command::SUCCESS;
    }

    private function resolveProviderOption(InputInterface $input): ?string
    {
        $provider = $input->getOption('share-provider');

        if (is_string($provider) && trim($provider) !== '') {
            return trim($provider);
        }

        $alias = $input->getOption('provider');

        if (is_string($alias) && trim($alias) !== '') {
            return trim($alias);
        }

        return null;
    }

    private function hasExplicitProvider(InputInterface $input): bool
    {
        return $this->resolveProviderOption($input) !== null;
    }

    private function resolveModeFromInput(InputInterface $input): string
    {
        $mode = strtolower(trim((string) $input->getOption('mode')));

        if ($mode === '') {
            return ShareWizard::MODE_SERVE;
        }

        $mode = match ($mode) {
            'server', 'php' => ShareWizard::MODE_SERVE,
            'hmr', 'vite', 'frontend', 'fe' => ShareWizard::MODE_DEV,
            'help', 'docs' => ShareWizard::MODE_GUIDE,
            default => $mode,
        };

        if (!in_array($mode, [ShareWizard::MODE_SERVE, ShareWizard::MODE_DEV, ShareWizard::MODE_GUIDE], true)) {
            throw new \InvalidArgumentException(sprintf(
                "Unknown share mode '%s'. Use serve, dev, or guide.",
                $mode,
            ));
        }

        return $mode;
    }

    private function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
