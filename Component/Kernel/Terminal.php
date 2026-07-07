<?php

/**
 *      ****  *  *     *  ****  ****  *    *
 *      *  *  *  * *   *  *  *  *  *   *  *
 *      ****  *  *  *  *  *  *  *  *    *
 *      *     *  *   * *  *  *  *  *   *  *
 *      *     *  *    **  ****  ****  *    *
 * @author   Pinoox
 * @link https://www.pinoox.com/
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Component\Kernel;

use Pinoox\Component\Helpers\ConsoleApplication as ConsoleApplicationHelper;
use Pinoox\Component\Helpers\Str;
use Pinoox\Component\Runtime\RuntimeMode;
use Pinoox\Component\Package\AppManager;
use Pinoox\Portal\App\AppEngine;
use Symfony\Component\Console\Application;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class Terminal
{
    private Application $application;

    private array $commands = [];

    public function __construct()
    {
        ConsoleApplicationHelper::bootUtf8();

        $this->application = new Application();

        if (RuntimeMode::bootDebugEnabled()) {
            $this->application->setCatchExceptions(false);
        }
    }

    public function run(): void
    {
        $this->finds();
        $this->bindCommands();

        $this->application->run(null, ConsoleApplicationHelper::output());
    }

    public function addCommand(object $command): void
    {
        ConsoleApplicationHelper::addCommand($this->application, $command);
    }

    private function finds(): void
    {
        $this->loadTerminals(PINOOX_CORE_PATH);
        $this->loadComposerTerminals();

        $packages = AppEngine::all();
        /**
         * @var AppManager $app
         */

        foreach ($packages as $app) {
            $this->loadTerminals($app->path(), $app->package());
        }
    }

    private function loadComposerTerminals(): void
    {
        $commands = [
            \Pinoox\Terminal\DevDB\DevDbStatusCommand::class,
            \Pinoox\Terminal\DevDB\DevDbTablesCommand::class,
            \Pinoox\Terminal\DevDB\DevDbInspectCommand::class,
            \Pinoox\Terminal\DevDB\DevDbExploreCommand::class,
            \Pinoox\Terminal\DevDB\DevDbClearCommand::class,
            \Pinoox\Terminal\DevDB\DevDbExportCommand::class,
            \Pinoox\Terminal\DevDB\DevDbSeedCommand::class,
        ];

        foreach ($commands as $command) {
            if (class_exists($command)) {
                $this->addCommand(new $command());
            }
        }
    }

    private function loadTerminals(string $path, ?string $package = null)
    {
        $path = Str::ds($path);
        if (!Str::lastHas($path, '/'))
            $path .= '/';
        if (!is_dir($path . 'Terminal'))
            return;
        $finder = new Finder();
        $finder->in($path . 'Terminal')
            ->files()
            ->filter(static function (SplFileInfo $file) {
                return $file->isDir() || \preg_match('/Command.(php)$/', $file->getPathname());
            });

        /**
         * @var SplFileInfo $f
         */

        foreach ($finder as $f) {
            $loc = $f->getPath();
            $namespace = !empty($package) ? "App\\" . $package . '\\' : "Pinoox" . '\\';
            $namespace = $namespace . str_replace($path, '', $loc) . '\\';
            $namespace = str_replace('/', '\\', $namespace);
            $this->commands[] = [
                'path' => $path,
                'fileName' => $f->getFilename(),
                'className' => $f->getBasename('.php'),
                'namespace' => $namespace,
            ];
        }
    }

    private function bindCommands(): void
    {
        if (empty($this->commands)) exit("there isn't any commands");

        //register commands
        foreach ($this->commands as $c) {
            $command = $c['namespace'] . $c['className'];
            $this->addCommand(new $command());
        }

    }

}
