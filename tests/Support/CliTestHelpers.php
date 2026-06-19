<?php

use Pinoox\Component\Helpers\ConsoleApplication as ConsoleApplicationHelper;
use Pinoox\Component\Terminal;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;

/**
 * Build an anonymous Terminal command that uses one or more CLI traits (for isolated unit-style tests).
 *
 * @param list<string> $traits Fully-qualified trait class names
 */
function cliTraitProbe(array $traits): Terminal
{
    $traits = array_values(array_unique($traits));
    $className = 'CliTraitProbe_' . substr(md5(implode('|', $traits)), 0, 12);

    if (!class_exists($className, false)) {
        $useStatements = implode(' ', array_map(
            static fn (string $trait) => 'use \\' . ltrim($trait, '\\') . ';',
            $traits,
        ));

        eval('#[\\Symfony\\Component\\Console\\Attribute\\AsCommand(name: \'cli:trait-probe\')]
        class ' . $className . ' extends \\Pinoox\\Component\\Terminal {
            ' . $useStatements . '
        }');
    }

    return new $className();
}

function cliTraitInvoke(object $command, string $method, mixed ...$args): mixed
{
    $reflection = new ReflectionMethod($command, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($command, ...$args);
}

/**
 * @param list<object> $commands
 */
function cliApplication(array $commands): Application
{
    $application = new Application();
    $application->setAutoExit(false);

    foreach ($commands as $command) {
        ConsoleApplicationHelper::addCommand($application, $command);
    }

    return $application;
}

/**
 * @return list<class-string<Command>>
 */
function cliCoreCommandClasses(): array
{
    static $classes = null;

    if (is_array($classes)) {
        return $classes;
    }

    $terminalPath = rtrim(str_replace('\\', '/', testCoreRoot()), '/') . '/Terminal';
    $classes = [];

    if (!is_dir($terminalPath)) {
        return $classes;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($terminalPath, FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), 'Command.php')) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($terminalPath) + 1));
        $directory = dirname($relative);

        $namespace = 'Pinoox\\Terminal';
        if ($directory !== '.' && $directory !== '') {
            $namespace .= '\\' . str_replace('/', '\\', $directory);
        }

        $className = $namespace . '\\' . $file->getBasename('.php');

        if (class_exists($className)) {
            $classes[] = $className;
        }
    }

    sort($classes);

    return $classes;
}

/**
 * @param class-string<Command> $className
 */
function cliInstantiateCommand(string $className): Command
{
    $command = new $className();

    if (!$command instanceof Command) {
        throw new InvalidArgumentException('Expected Symfony command: ' . $className);
    }

    return $command;
}

/**
 * @return list<string>
 */
function cliCommandNames(Command $command): array
{
    $names = [];

    if ($command->getName() !== null && $command->getName() !== '') {
        $names[] = $command->getName();
    }

    if (method_exists($command, 'getAliases')) {
        foreach ($command->getAliases() as $alias) {
            $names[] = $alias;
        }
    }

    return array_values(array_unique($names));
}

function cliExpectOptionalPackageArgument(Command $command, string $argument = 'package'): void
{
    $definition = $command->getDefinition();

    if (!$definition->hasArgument($argument)) {
        return;
    }

    $arg = $definition->getArgument($argument);

    expect($arg->isRequired())->toBeFalse()
        ->and($arg->getDefault())->toBeNull();
}

function cliExpectRequiredArgument(Command $command, string $argument): void
{
    expect($command->getDefinition()->getArgument($argument)->isRequired())->toBeTrue();
}
