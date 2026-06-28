<?php

namespace Pinoox\Terminal\Factory;

use Pinoox\Component\Helpers\Str;
use Pinoox\Component\Terminal;
use Pinoox\Portal\App\AppEngine;
use Pinoox\Portal\StubGenerator;
use Pinoox\Support\SystemConfig;
use Pinoox\Terminal\Concerns\SelectsPackage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'factory:create',
    description: 'Create a new model factory class in an app',
    aliases: ['make:factory'],
)]
class FactoryCreateCommand extends Terminal
{
    use SelectsPackage;

    protected function configure(): void
    {
        $this
            ->setHelp(
                <<<'HELP'
Creates a model factory stub inside database/factories/ for the selected app.

Examples:

  php pinoox factory:create ProductFactory com_my_shop
  php pinoox factory:create Product com_my_shop --model=ProductModel

HELP
            )
            ->addArgument('factory', InputArgument::REQUIRED, 'Factory name (e.g. ProductFactory or Product)')
            ->addArgument('package', InputArgument::OPTIONAL, 'App package name (e.g. com_my_shop). Leave empty to pick from the list.')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Model class name (e.g. ProductModel or App\\pkg\\Model\\ProductModel)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        parent::execute($input, $output);

        $io = new SymfonyStyle($input, $output);
        $package = $this->resolvePackageRequired($input, $output, $io, [
            'sectionTitle' => 'Create factory in',
            'appsOnly' => true,
        ]);

        $factory = $this->factoryClassName((string) $input->getArgument('factory'));
        $model = $this->modelClassName($package, $factory, $input->getOption('model'));
        $path = $this->factoryPath($package, $factory);

        try {
            StubGenerator::generate('factory.create.stub', $path, [
                'copyright' => StubGenerator::get('copyright.stub'),
                'namespace' => 'App\\' . $package . '\\database\\factories',
                'classname' => $factory,
                'model' => $model,
                'model_class' => $this->classBasename($model),
            ]);

            $this->newLine();
            $this->success('Factory created successfully');
            $this->newLine();
            $this->info('  Name:      ' . $factory);
            $this->info('  Model:     ' . $model);
            $this->info('  Location:  ' . $path);
            $this->info('  Package:   ' . $package);
            $this->newLine();
            $this->warning('  Use it with: ' . $this->classBasename($model) . '::factory()->create()');
            $this->newLine();

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function factoryClassName(string $factory): string
    {
        $factory = ucfirst(Str::toCamelCase($factory));

        return str_ends_with($factory, 'Factory') ? $factory : $factory . 'Factory';
    }

    private function modelClassName(string $package, string $factory, mixed $option): string
    {
        if (is_string($option) && trim($option) !== '') {
            $model = trim($option, '\\');

            if (str_contains($model, '\\')) {
                return $model;
            }

            $model = ucfirst(Str::toCamelCase($model));

            return 'App\\' . $package . '\\Model\\' . $model;
        }

        $base = substr($factory, 0, -7);
        $model = str_ends_with($base, 'Model') ? $base : $base . 'Model';

        return 'App\\' . $package . '\\Model\\' . $model;
    }

    private function factoryPath(string $package, string $factory): string
    {
        $dir = AppEngine::path($package) . '/' . trim(SystemConfig::rawPath('app_factories', 'database/factories'), '/\\');

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir . '/' . $factory . '.php';
    }

    private function classBasename(string $class): string
    {
        $parts = explode('\\', $class);

        return end($parts) ?: $class;
    }
}
