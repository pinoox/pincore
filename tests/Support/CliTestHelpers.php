<?php

use Pinoox\Component\Terminal;
use Symfony\Component\Console\Application;

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

        eval('class ' . $className . ' extends \\Pinoox\\Component\\Terminal {
            protected static $defaultName = \'cli:trait-probe\';
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
        $application->add($command);
    }

    return $application;
}
