<?php

namespace Pinoox\Terminal\Theme;

use Pinoox\Component\Package\AppManifest;
use Pinoox\Component\Package\Engine\AppEngine;
use Pinoox\Component\Template\Frontend\ThemeFrontend;
use Pinoox\Component\Terminal;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'theme:create',
    description: 'Create a theme folder with theme.php and a frontend stack stub',
    aliases: ['theme:scaffold'],
)]
class ThemeCreateCommand extends Terminal
{
    use SelectsPackage;

    /** @var list<string> */
    private const STACKS = ['twig', 'vite', 'vue', 'react'];

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Create apps/{package}/theme/{name}/ with theme.php and frontend stack files.

Stacks:
  twig   Twig-only — no manifest/entry/dev (assets via assets())
  vite   Vite hybrid — manifest dist/.vite/manifest.json, vite_*_tags() in Twig
  vue    Vue SPA/hybrid — same Vite manifest; entry src/main.js
  react  React SPA/hybrid — entry src/main.jsx

frontend.config.php fields (Vite stacks):
  entry     Vite input — same path as vite_js_tags('…')
  manifest  dist/.vite/manifest.json (not webpack mix-manifest)
  dev.url   VITE_DEV_SERVER when VITE_DEV=true

Examples:
  php pinoox theme:create panel --stack=vue
  php pinoox theme:create admin com_my_shop --stack=vite
  pinx theme:create storefront --stack=twig

Legacy webpack (mix-manifest) is deprecated — use vite/vue/react stacks with vite_tags().
HELP
            )
            ->addArgument('theme', InputArgument::REQUIRED, 'Theme folder name (e.g. panel, admin)')
            ->addArgument('package', InputArgument::OPTIONAL, 'App package (defaults to interactive pick)')
            ->addOption('stack', null, InputOption::VALUE_REQUIRED, 'Stack: twig, vite, vue, react (default: twig)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $themeName = trim((string) $input->getArgument('theme'));

        if ($themeName === '' || !preg_match('/^[a-z][a-z0-9_-]*$/i', $themeName)) {
            $io->error('Theme name must be a non-empty folder name (letters, numbers, -, _).');

            return Command::FAILURE;
        }

        $stack = strtolower(trim((string) ($input->getOption('stack') ?: 'twig')));

        if (!in_array($stack, self::STACKS, true)) {
            $io->error('Unknown stack "' . $stack . '". Use twig, vite, vue, or react.');

            return Command::FAILURE;
        }

        try {
            $package = $this->resolvePackageRequired($input, $output, $io);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (!AppEngine::exists($package)) {
            $io->error("App '{$package}' was not found.");

            return Command::FAILURE;
        }

        $themePath = rtrim(str_replace('\\', '/', AppEngine::path($package) . '/theme/' . $themeName), '/');

        if (is_dir($themePath) && (is_file($themePath . '/theme.php') || is_file($themePath . '/frontend.config.php'))) {
            $io->error("Theme '{$themeName}' already exists in {$themePath}.");

            return Command::FAILURE;
        }

        $this->writeThemeManifest($package, $themeName, $themePath);
        ThemeFrontend::forPackageAndTheme($package, $themeName)->scaffold($stack);

        $io->success("Theme '{$themeName}' created for {$package} (stack: {$stack})");
        $io->definitionList(
            ['Path' => $themePath],
            ['Stack' => $stack],
            ['Manifest' => $stack === 'twig' ? '(none — twig-only)' : 'dist/.vite/manifest.json'],
            ['Entry' => $stack === 'react' ? 'src/main.jsx' : ($stack === 'twig' ? '(none)' : 'src/main.js')],
        );

        if ($stack !== 'twig') {
            $io->writeln('Next: php pinoox fe ' . $package . ' install --theme=' . $themeName);
            $io->writeln('Then: php pinoox fe ' . $package . ' dev --theme=' . $themeName);
        }

        return Command::SUCCESS;
    }

    private function writeThemeManifest(string $package, string $themeName, string $themePath): void
    {
        $corePath = defined('PINOOX_CORE_PATH')
            ? rtrim(str_replace('\\', '/', (string) PINOOX_CORE_PATH), '/')
            : dirname(__DIR__, 2);
        $stub = (string) file_get_contents($corePath . '/stubs/theme.php.stub');

        $content = str_replace(
            ['{{package}}', '{{developer}}', '{{displayName}}', '{{description}}', "'name' => 'default'"],
            [
                $package,
                'pinoox',
                AppManifest::displayName($package),
                AppManifest::displayName($package) . ' theme',
                "'name' => '" . $themeName . "'",
            ],
            $stub,
        );

        $filesystem = new Filesystem();
        $filesystem->mkdir($themePath);
        $filesystem->dumpFile($themePath . '/theme.php', $content);
    }
}
