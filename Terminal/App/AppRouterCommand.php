<?php

namespace Pinoox\Terminal\App;

use Pinoox\Component\Package\Routing\AppRouteMatcher;
use Pinoox\Component\Terminal;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\App\AppRouter;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:router',
    aliases: ['router'],
    description: 'View or edit URL-to-app mappings in app-router.config.php',
)]

class AppRouterCommand extends Terminal
{
    use SelectsPackage;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Maps a URL path to an app package (which app handles /shop, /panel, etc.).

Path routes live in {project}/config/app-router.config.php (stub fallback: pincore/config/).
Host/domain routes live in {project}/config/domain.config.php and are checked first.

Examples:

  php pinoox app:router
  php pinoox app:domain
  php pinoox app:router -p com_my_shop
  php pinoox app:router set /shop com_my_shop
  php pinoox app:router remove /shop
  php pinoox app:router export
  php pinoox app:router export --format=php
  php pinoox app:router export --file=platform/app-router.config.php
  php pinoox app:router sync routes.json
  php pinoox app:router sync platform/app-router.config.php
  php pinoox app:router sync routes.json --merge
  php pinoox app:router edit

HELP
            )
            ->addOption('package', 'p', InputOption::VALUE_OPTIONAL, 'Show routes for one app package')
            ->addOption('path', 'u', InputOption::VALUE_OPTIONAL, 'Show which app handles one URL path')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Route file for export or sync (JSON or PHP)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Export/import format: json or php (auto-detected from file extension)')
            ->addOption('json', null, InputOption::VALUE_REQUIRED, 'Inline JSON object for sync')
            ->addOption('merge', 'm', InputOption::VALUE_NONE, 'Merge imported routes with existing ones (sync)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompts')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview sync changes without saving')
            ->addArgument('action', InputArgument::OPTIONAL, 'set, remove, export, sync, or edit')
            ->addArgument('route', InputArgument::OPTIONAL, 'URL path (set/remove) or route file path (sync)')
            ->addArgument('packageName', InputArgument::OPTIONAL, 'Target app package when using set');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $input->getOption('package');
        $path = $input->getOption('path');
        $action = $input->getArgument('action');

        return match ($action) {
            'set' => $this->setRoute($input, $output),
            'remove' => $this->removeRoute($input, $output),
            'export' => $this->exportRoutes($input, $output),
            'sync' => $this->syncRoutes($input, $output),
            'edit' => $this->editRoutes($input, $output),
            null, '' => $this->listRoutes($input, $output, $package, $path),
            default => $this->unknownAction($io, (string) $action),
        };
    }

    private function unknownAction(SymfonyStyle $io, string $action): int
    {
        $io->error("Unknown action '$action'. Use set, remove, export, sync, or edit.");

        return Command::INVALID;
    }

    private function listRoutes(
        InputInterface $input,
        OutputInterface $output,
        mixed $package,
        mixed $path,
    ): int {
        if ($package) {
            $this->getRoutesByPackage($input, $output);
        } elseif ($path) {
            $this->getRoutesByPath($input, $output);
        } else {
            $this->getRoutes($input, $output);
        }

        return Command::SUCCESS;
    }

    private function removeRoute(InputInterface $input, OutputInterface $output): int
    {
        $route = $input->getArgument('route');

        if (!is_string($route) || $route === '') {
            $output->writeln('<error>Route path is required. Example: php pinoox app:router remove /shop</error>');

            return Command::INVALID;
        }

        AppRouter::delete($route);
        $output->writeln("<info>Route <options=bold>'$route'</> removed</info>");

        return Command::SUCCESS;
    }

    private function setRoute(InputInterface $input, OutputInterface $output): int
    {
        $route = $input->getArgument('route');
        $packageName = $input->getArgument('packageName');

        if (!is_string($route) || $route === '') {
            $output->writeln('<error>Route path is required. Example: php pinoox app:router set /shop com_my_shop</error>');

            return Command::INVALID;
        }

        if (!$packageName && $input->isInteractive()) {
            $io = new SymfonyStyle($input, $output);
            $packageName = $this->resolvePackageRequired($input, $output, $io, [
                'appsOnly' => true,
                'sectionTitle' => 'Assign route to app',
            ]);
        }

        if (!is_string($packageName) || $packageName === '') {
            $output->writeln('<error>Package name is required. Example: php pinoox app:router set /shop com_my_shop</error>');

            return Command::INVALID;
        }

        AppRouter::set($route, $packageName);
        $output->writeln("<info>Route <options=bold>'$route'</> set to package <options=bold>'$packageName'</></info>");

        return Command::SUCCESS;
    }

    private function exportRoutes(InputInterface $input, OutputInterface $output): int
    {
        $routes = AppRouter::routes();
        $format = $this->resolveRouteFormat($input);
        $payload = $this->encodeRoutes($routes, $format);
        $file = $this->resolveRouteFilePath($input);

        if ($file !== null) {
            if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0755, true) && !is_dir(dirname($file))) {
                $output->writeln('<error>Could not create directory for export file.</error>');

                return Command::FAILURE;
            }

            if (file_put_contents($file, $payload) === false) {
                $output->writeln('<error>Could not write export file: ' . $file . '</error>');

                return Command::FAILURE;
            }

            $output->writeln('<info>Exported ' . count($routes) . ' route(s) to ' . $file . ' (' . $format . ')</info>');

            return Command::SUCCESS;
        }

        $output->write($payload);

        return Command::SUCCESS;
    }

    private function syncRoutes(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $imported = $this->loadRoutesFromInput($input);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }

        $current = AppRouter::routes();
        $merge = (bool) $input->getOption('merge');
        $next = $merge ? array_merge($current, $imported) : $imported;
        $next = AppRouteMatcher::normalizeRoutes($next);

        $this->printSyncPreview($output, $current, $next, $merge);

        if ((bool) $input->getOption('dry-run')) {
            $io->note('Dry run — no changes saved.');

            return Command::SUCCESS;
        }

        if (!$input->getOption('force') && $input->isInteractive()) {
            if (!$io->confirm('Apply these route changes?', true)) {
                $io->warning('Sync cancelled.');

                return Command::SUCCESS;
            }
        }

        AppRouter::setData($next);
        $io->success('Routes synced (' . count($next) . ' mapping(s)).');

        return Command::SUCCESS;
    }

    private function editRoutes(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->isInteractive()) {
            $io->error('Interactive edit requires a TTY. Use export/sync for non-interactive bulk edits.');

            return Command::INVALID;
        }

        $routes = AppRouter::routes();
        $dirty = false;

        while (true) {
            $io->section('App router editor');
            $this->printRouteTable($output, $routes);
            $io->writeln('');

            $action = (string) $io->choice('What would you like to do?', [
                'add' => 'Add route',
                'edit' => 'Change route',
                'remove' => 'Remove route',
                'import' => 'Import from file (JSON or PHP)',
                'save' => 'Save and exit',
                'quit' => 'Exit without saving',
            ], 'add');

            if ($action === 'save') {
                if (!$dirty) {
                    $io->note('No changes to save.');

                    return Command::SUCCESS;
                }

                AppRouter::setData($routes);
                $io->success('Routes saved (' . count($routes) . ' mapping(s)).');

                return Command::SUCCESS;
            }

            if ($action === 'quit') {
                if ($dirty && !$io->confirm('Discard unsaved changes?', false)) {
                    continue;
                }

                $io->warning('Changes discarded.');

                return Command::SUCCESS;
            }

            if ($action === 'import') {
                $file = trim((string) $io->ask('Route file path (JSON or PHP)'));
                if ($file === '') {
                    continue;
                }

                try {
                    $imported = $this->loadRoutesFromFile($file);
                } catch (\InvalidArgumentException $exception) {
                    $io->error($exception->getMessage());
                    continue;
                }

                $merge = $io->confirm('Merge with current routes?', true);
                $routes = $merge
                    ? AppRouteMatcher::normalizeRoutes(array_merge($routes, $imported))
                    : AppRouteMatcher::normalizeRoutes($imported);
                $dirty = true;
                continue;
            }

            if ($action === 'remove') {
                if ($routes === []) {
                    $io->note('No routes to remove.');
                    continue;
                }

                $path = (string) $io->choice('Remove which path?', array_combine(array_keys($routes), array_keys($routes)));
                unset($routes[$path]);
                $routes = AppRouteMatcher::normalizeRoutes($routes);
                $dirty = true;
                continue;
            }

            if ($action === 'edit') {
                if ($routes === []) {
                    $io->note('No routes to edit.');
                    continue;
                }

                $path = (string) $io->choice('Edit which path?', array_combine(array_keys($routes), array_keys($routes)));
                $packageName = $this->resolvePackageRequired($input, $output, $io, [
                    'appsOnly' => true,
                    'sectionTitle' => 'Assign route to app',
                ]);
                $routes[$path] = $packageName;
                $routes = AppRouteMatcher::normalizeRoutes($routes);
                $dirty = true;
                continue;
            }

            $path = trim((string) $io->ask('URL path (e.g. /shop)', '/'));
            $packageName = $this->resolvePackageRequired($input, $output, $io, [
                'appsOnly' => true,
                'sectionTitle' => 'Assign route to app',
            ]);
            $normalizedPath = $path === '*' ? '*' : AppRouteMatcher::normalize($path);
            $routes[$normalizedPath] = $packageName;
            $routes = AppRouteMatcher::normalizeRoutes($routes);
            $dirty = true;
        }
    }

    /**
     * @return array<string, string>
     */
    private function loadRoutesFromInput(InputInterface $input): array
    {
        $inlineJson = $input->getOption('json');
        if (is_string($inlineJson) && $inlineJson !== '') {
            return $this->decodeRoutesJson($inlineJson);
        }

        $file = $this->resolveRouteFilePath($input) ?? $input->getArgument('route');
        if (!is_string($file) || $file === '') {
            throw new \InvalidArgumentException(
                'Provide a route file path, --file=path, or --json=\'{"\/shop":"com_my_shop"}\'.',
            );
        }

        return $this->loadRoutesFromFile($file, $this->resolveRouteFormat($input, $file));
    }

    /**
     * @return array<string, string>
     */
    private function loadRoutesFromFile(string $file, ?string $format = null): array
    {
        if (!is_file($file)) {
            throw new \InvalidArgumentException('File not found: ' . $file);
        }

        $format ??= $this->detectRouteFormatFromPath($file);

        if ($format === 'php') {
            return $this->decodeRoutesPhpFile($file);
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new \InvalidArgumentException('Could not read file: ' . $file);
        }

        return $this->decodeRoutesJson($contents);
    }

    /**
     * @return array<string, string>
     */
    private function decodeRoutesPhpFile(string $file): array
    {
        $data = include $file;

        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid PHP route file. Expected return [\'\/shop\' => \'com_my_shop\'];');
        }

        return AppRouteMatcher::normalizeRoutes($data);
    }

    /**
     * @return array<string, string>
     */
    private function decodeRoutesJson(string $json): array
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Invalid JSON. Expected an object like {"\/shop":"com_my_shop"}.');
        }

        return AppRouteMatcher::normalizeRoutes($decoded);
    }

    /**
     * @param array<string, string> $routes
     */
    private function encodeRoutes(array $routes, string $format): string
    {
        if ($format === 'php') {
            return $this->formatRoutesPhp($routes);
        }

        return json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    /**
     * @param array<string, string> $routes
     */
    private function formatRoutesPhp(array $routes): string
    {
        if ($routes === []) {
            return "<?php\n\nreturn [];\n";
        }

        $lines = ['<?php', '', 'return ['];

        foreach ($routes as $path => $package) {
            $lines[] = '    ' . var_export($path, true) . ' => ' . var_export($package, true) . ',';
        }

        $lines[] = '];';

        return implode("\n", $lines) . "\n";
    }

    private function resolveRouteFormat(InputInterface $input, ?string $file = null): string
    {
        $format = $input->getOption('format');
        if (is_string($format) && $format !== '') {
            $format = strtolower($format);

            if (in_array($format, ['json', 'php'], true)) {
                return $format;
            }

            throw new \InvalidArgumentException("Invalid format '$format'. Use json or php.");
        }

        $file ??= $this->resolveRouteFilePath($input) ?? $input->getArgument('route');
        if (is_string($file) && $file !== '') {
            return $this->detectRouteFormatFromPath($file);
        }

        return 'json';
    }

    private function detectRouteFormatFromPath(string $file): string
    {
        return str_ends_with(strtolower($file), '.php') ? 'php' : 'json';
    }

    private function resolveRouteFilePath(InputInterface $input): ?string
    {
        $file = $input->getOption('file');

        return is_string($file) && $file !== '' ? $file : null;
    }

    /**
     * @param array<string, string> $current
     * @param array<string, string> $next
     */
    private function printSyncPreview(OutputInterface $output, array $current, array $next, bool $merge): void
    {
        $output->writeln('');
        $output->writeln($merge ? '<info>Sync preview (merge)</info>' : '<info>Sync preview (replace)</info>');

        $added = array_diff_assoc($next, $current);
        $removed = array_diff_assoc($current, $next);
        $changed = [];

        foreach ($next as $path => $package) {
            if (isset($current[$path]) && $current[$path] !== $package) {
                $changed[$path] = $package;
            }
        }

        if ($added === [] && $removed === [] && $changed === []) {
            $output->writeln('<comment>No changes detected.</comment>');
            $output->writeln('');

            return;
        }

        $rows = [];
        foreach ($added as $path => $package) {
            $rows[] = ['+', $path, $package, $this->packageStatusLabel($package)];
        }
        foreach ($changed as $path => $package) {
            $rows[] = ['~', $path, $package . ' (was ' . $current[$path] . ')', $this->packageStatusLabel($package)];
        }
        foreach ($removed as $path => $package) {
            $rows[] = ['-', $path, $package, 'removed'];
        }

        $table = new Table($output);
        $table->setStyle('box-double')
            ->setHeaders(['', 'path', 'package', 'note'])
            ->setRows($rows);
        $table->render();
        $output->writeln('');
    }

    private function packageStatusLabel(string $package): string
    {
        if (!AppEngine::exists($package)) {
            return 'app missing';
        }

        try {
            if (!(bool) AppEngine::config($package)->get('enable')) {
                return 'app disabled';
            }
        } catch (\Throwable) {
            return 'app unavailable';
        }

        return 'ok';
    }

    private function getRoutes(InputInterface $input, OutputInterface $output): void
    {
        $routes = AppRouter::routes();
        $output->writeln('');
        $output->writeln('All app routes:');
        $this->printRouteTable($output, $routes);
        $output->writeln('');
        $output->writeln('<comment>Tip: php pinoox app:router export --format=php --file=platform/app-router.config.php</comment>');
        $output->writeln('<comment>     php pinoox app:router sync platform/app-router.config.php</comment>');
        $output->writeln('<comment>     php pinoox app:router edit</comment>');
        $output->writeln('');
    }

    /**
     * @param array<string, string> $routes
     */
    private function printRouteTable(OutputInterface $output, array $routes): void
    {
        if ($routes === []) {
            $output->writeln('<comment>No routes configured.</comment>');

            return;
        }

        $rows = array_map(
            fn (string $path, string $package): array => [$path, $package, $this->packageStatusLabel($package)],
            array_keys($routes),
            array_values($routes),
        );

        $table = new Table($output);
        $table->setStyle('box-double')
            ->setHeaders(['path', 'package', 'status'])
            ->setRows($rows);
        $table->render();
    }

    private function printTable(OutputInterface $output, $rows): void
    {
        $table = new Table($output);
        $table->setStyle('box-double')
            ->setHeaders(['path', 'package'])
            ->setRows($rows);
        $table->render();
    }

    private function getRoutesByPackage(InputInterface $input, OutputInterface $output): void
    {
        $io = new SymfonyStyle($input, $output);
        $package = $input->getOption('package');

        if (!$package && $input->isInteractive()) {
            $package = $this->resolvePackageRequired($input, $output, $io, [
                'sectionTitle' => 'Show routes for',
            ]);
        }

        $routes = AppRouter::getByPackage((string) $package);
        $output->writeln('');
        $output->writeln("Routes for package <fg=yellow>$package</>:");

        $rows = [];
        foreach ($routes as $route => $packageName) {
            $rows[] = [$route, $packageName];
        }
        $this->printTable($output, $rows);
        $output->writeln('');
    }

    private function getRoutesByPath(InputInterface $input, OutputInterface $output): void
    {
        $path = $input->getOption('path');
        $packageName = AppRouter::get($path);
        $output->writeln('');
        $output->writeln("Routes for path <fg=yellow>$path</>:");
        $rows = [
            [$path, $packageName],
        ];
        $this->printTable($output, $rows);
        $output->writeln('');
    }
}
