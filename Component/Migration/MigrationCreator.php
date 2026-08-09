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

use Pinoox\Component\StubGenerator;
use RuntimeException;

class MigrationCreator
{
    private string $stubsPath;

    public function __construct(?string $stubsPath = null)
    {
        $this->stubsPath = rtrim($stubsPath ?? dirname(__DIR__, 2) . '/stubs', '/\\') . '/';
    }

    /**
     * @return array{path: string, name: string, table: ?string, type: string}
     */
    public function create(
        string $path,
        string $name,
        string $namespace,
        ?string $create = null,
        ?string $table = null,
    ): array {
        $parsed = MigrationNameParser::parse($name, $create, $table);
        $directory = rtrim(str_replace('\\', '/', $path), '/');

        if ($directory !== '' && !is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create migration directory: ' . $directory);
        }

        $fileName = date('Y_m_d_His') . '_' . $parsed['name'] . '.php';
        $exportPath = $directory . '/' . $fileName;

        if (is_file($exportPath)) {
            throw new RuntimeException('Migration already exists: ' . $fileName);
        }

        $generator = new StubGenerator($this->stubsPath);
        $stubTable = $parsed['table'] ?? 'table';

        $generator->generate($this->stubName($parsed['type']), $exportPath, [
            'copyright' => $generator->get('copyright.stub'),
            'table' => $stubTable,
            'namespace' => $namespace,
        ]);

        if (!is_file($exportPath)) {
            throw new RuntimeException('Can\'t generate a new migration class!');
        }

        return [
            'path' => $exportPath,
            'name' => pathinfo($fileName, PATHINFO_FILENAME),
            'table' => $parsed['table'],
            'type' => $parsed['type'],
        ];
    }

    private function stubName(string $type): string
    {
        return match ($type) {
            MigrationNameParser::TYPE_CREATE => 'migration.create.stub',
            MigrationNameParser::TYPE_UPDATE => 'migration.update.stub',
            MigrationNameParser::TYPE_DROP => 'migration.drop.stub',
            default => 'migration.blank.stub',
        };
    }
}
