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

namespace Pinoox\Component\Database\Factories;

use Pinoox\Portal\App\AppEngine;
use Pinoox\Support\PackageContext;
use Pinoox\Support\SystemConfig;
use Symfony\Component\Finder\Finder;

class FactoryToolkit
{
    /** @var array<string, list<array{file: string, name: string, class: string, instance: Factory}>> */
    private static array $cache = [];

    private string $package = '';
    private string $factoryPath = '';
    private string $factoryFolder = 'database/factories';
    private array $errors = [];

    /** @var list<array{file: string, name: string, class: string, instance: Factory}> */
    private array $factories = [];

    public static function flush(): void
    {
        self::$cache = [];
    }

    public function package(string $package): self
    {
        $this->package = $package;

        return $this;
    }

    public function load(): self
    {
        try {
            $this->initializeFactoryPath();

            if (isset(self::$cache[$this->package])) {
                $this->factories = self::$cache[$this->package];

                return $this;
            }

            $this->loadFactoryFiles();
            self::$cache[$this->package] = $this->factories;
        } catch (\Throwable $e) {
            $this->addError($e);
        }

        return $this;
    }

    /**
     * @return list<array{file: string, name: string, class: string, instance: Factory}>
     */
    public function getFactories(): array
    {
        return $this->factories;
    }

    public function getErrors(bool $latest = true): array|string
    {
        if ($latest) {
            return end($this->errors) ?: '';
        }

        return $this->errors;
    }

    public function isSuccess(): bool
    {
        return empty($this->errors);
    }

    private function initializeFactoryPath(): void
    {
        if ($this->package === 'platform') {
            $this->factoryPath = SystemConfig::path('platform_factories');
            if (!is_dir($this->factoryPath)) {
                $this->factoryPath = rtrim(SystemConfig::corePath(), '/\\') . '/database/factories';
            }
        } else {
            $this->factoryFolder = trim(SystemConfig::rawPath('app_factories', 'database/factories'), '/\\');
            $this->factoryPath = AppEngine::path($this->package) . '/' . $this->factoryFolder;
        }
    }

    private function loadFactoryFiles(): void
    {
        if (!is_dir($this->factoryPath)) {
            return;
        }

        $finder = new Finder();
        $finder->in([$this->factoryPath])->files()->name('*.php');

        $previous = PackageContext::runtime();
        FactoryBase::usePackage($this->package);

        try {
            foreach ($finder as $file) {
                $path = $file->getRealPath();
                if ($path === false) {
                    continue;
                }

                $factory = require $path;

                if ($factory instanceof Factory) {
                    $this->factories[] = [
                        'file' => $path,
                        'name' => pathinfo($path, PATHINFO_FILENAME),
                        'class' => get_class($factory),
                        'instance' => $factory,
                    ];
                }
            }
        } finally {
            FactoryBase::usePackage($previous);
        }
    }

    private function addError(\Throwable|string $error): void
    {
        $this->errors[] = is_string($error) ? $error : $error->getMessage();
    }
}
