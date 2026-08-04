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

namespace Pinoox\Component\Database\Seeder;

use Pinoox\Support\PackageContext;
use RuntimeException;

class SeederRunner
{
    /**
     * Run seeders for a package (current PackageContext when $package is null).
     *
     * Accepts file basename(s), or a named class extending SeederBase (FQCN / ::class).
     *
     * @param string|array<int, string> $name
     * @return int Number of seeders executed
     */
    public function run(string|array $name, ?string $package = null): int
    {
        $package = PackageContext::resolve($package);

        if (is_array($name)) {
            $count = 0;
            foreach ($name as $item) {
                $count += $this->run($item, $package);
            }

            return $count;
        }

        if ($this->isSeederClass($name)) {
            SeederBase::usePackage($package);
            try {
                /** @var SeederBase $instance */
                $instance = new $name($package);
                $instance->run();
            } finally {
                SeederBase::usePackage(null);
            }

            return 1;
        }

        $seeders = $this->resolve($name, $package);

        $count = 0;
        foreach ($seeders as $seeder) {
            $seeder['instance']->run();
            $count++;
        }

        return $count;
    }

    /**
     * Run all seeders for a package (current PackageContext when $package is null).
     *
     * @return int Number of seeders executed
     */
    public function runAll(?string $package = null): int
    {
        $package = PackageContext::resolve($package);
        $seeders = $this->resolve(null, $package);

        $count = 0;
        foreach ($seeders as $seeder) {
            $seeder['instance']->run();
            $count++;
        }

        return $count;
    }

    /**
     * Load and optionally filter seeders without executing them.
     *
     * @return list<array{file: string, name: string, class: string, instance: SeederBase}>
     */
    public function resolve(?string $name = null, ?string $package = null): array
    {
        $package = PackageContext::resolve($package);

        $toolkit = (new SeederToolkit())->package($package)->load();
        if (!$toolkit->isSuccess()) {
            throw new RuntimeException((string) $toolkit->getErrors());
        }

        $seeders = $toolkit->getSeeders();

        if ($name === null || $name === '') {
            return $seeders;
        }

        $filtered = array_values(array_filter(
            $seeders,
            fn(array $seeder) => $this->matchesName($seeder, $name)
        ));

        if ($filtered === []) {
            throw new RuntimeException(sprintf(
                'Seeder "%s" not found in package "%s".',
                $name,
                $package
            ));
        }

        return $filtered;
    }

    public function matchesName(array $seeder, string $name): bool
    {
        $needle = $this->normalizeName($name);
        $fileBase = $this->normalizeName($seeder['name'] ?? basename((string) ($seeder['file'] ?? ''), '.php'));

        if ($fileBase === $needle) {
            return true;
        }

        $class = $seeder['class'] ?? '';
        if (!is_string($class) || $class === '') {
            return false;
        }

        if ($this->normalizeName($class) === $needle) {
            return true;
        }

        $short = basename(str_replace('\\', '/', $class));
        if (str_contains($short, '@anonymous')) {
            return false;
        }

        return $this->normalizeName($short) === $needle;
    }

    public function normalizeName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        if (str_ends_with(strtolower($name), '.php')) {
            $name = substr($name, 0, -4);
        }

        return $name;
    }

    /**
     * @param class-string|string $name
     */
    private function isSeederClass(string $name): bool
    {
        return class_exists($name)
            && is_subclass_of($name, SeederBase::class)
            && !(new \ReflectionClass($name))->isAbstract();
    }
}
