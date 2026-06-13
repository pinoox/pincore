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

namespace Pinoox\Component\Migration;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Builder;
use Illuminate\Contracts\Database\Query\Expression;
use Pinoox\Portal\Database\DB;
use Pinoox\Support\PackageContext;

class MigrationBase extends Migration
{
    public Builder $schema;

    public static function usePackage(?string $package): void
    {
        PackageContext::use($package);
    }

    public function __construct(?string $package = null)
    {
        $package = PackageContext::resolve($package);
        $this->schema = DB::schema(DB::connectionNameForPackage($package));
        $this->schema->blueprintResolver(fn($table, $callback, $prefix) => new MigrationBlueprint($table, $callback, $prefix));
    }

    protected function table(string $name, ?string $package = null): string
    {
        return DB::tableName($name, $package ?? PackageContext::resolve());
    }

    protected function foreignTable(string $name, ?string $package = null): Expression
    {
        return DB::raw(DB::physicalTableName($name, $package ?? PackageContext::resolve()));
    }

}
