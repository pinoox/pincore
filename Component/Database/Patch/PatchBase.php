<?php

namespace Pinoox\Component\Database\Patch;

use Illuminate\Database\Schema\Builder;
use Pinoox\Component\Database\Seeder\SeederRunner;
use Pinoox\Portal\Database\DB;
use Pinoox\Support\PackageContext;

abstract class PatchBase
{
    protected Builder $schema;
    protected string $package = '';

    public static function usePackage(?string $package): void
    {
        PackageContext::use($package);
    }

    public function __construct(?string $package = null)
    {
        $this->package = PackageContext::resolve($package);
        $this->schema = DB::schema();
    }

    public function run(): void
    {
        $this->up();
    }

    public function up(): void
    {
    }

    public function down(): void
    {
    }

    public function canRollback(): bool
    {
        return false;
    }

    public function shouldRun(): bool
    {
        return true;
    }

    public function description(): string
    {
        return '';
    }

    public function metadata(): array
    {
        return [];
    }

    public function setPackage(string $package): static
    {
        $this->package = $package;

        return $this;
    }

    public function hasPackage(): bool
    {
        return $this->package !== '';
    }

    protected function getPackage(): string
    {
        return $this->package;
    }

    protected function getSchema(): Builder
    {
        return $this->schema;
    }

    /**
     * Run seeders by file basename (current package when $package is null).
     *
     * @param string|array<int, string> $name
     */
    protected function seed(string|array $name, ?string $package = null): void
    {
        (new SeederRunner())->run($name, $package);
    }

    /**
     * Run all seeders for a package (current package when $package is null).
     */
    protected function seedAll(?string $package = null): void
    {
        (new SeederRunner())->runAll($package);
    }
}
