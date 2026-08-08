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

use Pinoox\Component\Helpers\Str;

class MigrationNameParser
{
    public const TYPE_CREATE = 'create';

    public const TYPE_UPDATE = 'update';

    public const TYPE_DROP = 'drop';

    public const TYPE_BLANK = 'blank';

    private const TIMESTAMP_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_/';

    private const KNOWN_VERBS = [
        'create',
        'drop',
        'alter',
        'add',
        'remove',
        'modify',
        'update',
        'rename',
        'change',
    ];

    /**
     * More specific patterns first. Capture group 1 is the logical table.
     *
     * @var list<array{pattern: string, type: string}>
     */
    private const TABLE_NAME_PATTERNS = [
        ['pattern' => '/^create_(.+)_table$/', 'type' => self::TYPE_CREATE],
        ['pattern' => '/^drop_.+_from_(.+)$/', 'type' => self::TYPE_UPDATE],
        ['pattern' => '/^remove_.+_from_(.+)$/', 'type' => self::TYPE_UPDATE],
        ['pattern' => '/^add_.+_to_(.+)$/', 'type' => self::TYPE_UPDATE],
        ['pattern' => '/^modify_.+_in_(.+)$/', 'type' => self::TYPE_UPDATE],
        ['pattern' => '/^update_.+_in_(.+)$/', 'type' => self::TYPE_UPDATE],
        ['pattern' => '/^rename_.+_in_(.+)$/', 'type' => self::TYPE_UPDATE],
        ['pattern' => '/^drop_(.+)_table$/', 'type' => self::TYPE_DROP],
        ['pattern' => '/^alter_(.+)_table$/', 'type' => self::TYPE_UPDATE],
        ['pattern' => '/^.+_(?:to|from|in)_(.+)$/', 'type' => self::TYPE_UPDATE],
    ];

    private const SHORT_CREATE_PATTERN = '/^create_(.+)$/';

    /**
     * Parse a CLI migration name into stub type, table, and snake file basename.
     *
     * @return array{type: string, table: ?string, name: string}
     */
    public static function parse(string $name, ?string $create = null, ?string $table = null): array
    {
        $snakeName = self::toSnakeCase($name);
        $create = self::normalizeOption($create);
        $table = self::normalizeOption($table);

        if ($create !== null) {
            return [
                'type' => self::TYPE_CREATE,
                'table' => $create,
                'name' => $snakeName,
            ];
        }

        if ($table !== null) {
            return [
                'type' => self::TYPE_UPDATE,
                'table' => $table,
                'name' => $snakeName,
            ];
        }

        $guessed = self::guess($snakeName, true);

        if ($guessed['type'] === self::TYPE_CREATE && is_string($guessed['table']) && $guessed['table'] !== '') {
            $fileName = str_ends_with($snakeName, '_table')
                ? $snakeName
                : 'create_' . $guessed['table'] . '_table';

            return [
                'type' => self::TYPE_CREATE,
                'table' => $guessed['table'],
                'name' => $fileName,
            ];
        }

        if ($guessed['type'] !== self::TYPE_BLANK) {
            return [
                'type' => $guessed['type'],
                'table' => $guessed['table'],
                'name' => $snakeName,
            ];
        }

        if (!self::startsWithKnownVerb($snakeName) && $snakeName !== '') {
            return [
                'type' => self::TYPE_CREATE,
                'table' => $snakeName,
                'name' => 'create_' . $snakeName . '_table',
            ];
        }

        return [
            'type' => self::TYPE_BLANK,
            'table' => null,
            'name' => $snakeName,
        ];
    }

    /**
     * Guess type and table from an already-normalized snake name (no convenience wrap).
     *
     * @return array{type: string, table: ?string}
     */
    public static function guess(string $snakeName, bool $includeShortCreate = false): array
    {
        foreach (self::TABLE_NAME_PATTERNS as $definition) {
            if (preg_match($definition['pattern'], $snakeName, $matches) !== 1) {
                continue;
            }

            $table = self::normalizeTable($matches[1] ?? null);

            if ($table === null) {
                continue;
            }

            return [
                'type' => $definition['type'],
                'table' => $table,
            ];
        }

        if ($includeShortCreate && preg_match(self::SHORT_CREATE_PATTERN, $snakeName, $matches) === 1) {
            $table = self::normalizeTable($matches[1] ?? null);
            if ($table !== null) {
                return [
                    'type' => self::TYPE_CREATE,
                    'table' => $table,
                ];
            }
        }

        return [
            'type' => self::TYPE_BLANK,
            'table' => null,
        ];
    }

    public static function extractTableName(string $fileName): ?string
    {
        $clean = preg_replace(self::TIMESTAMP_PATTERN, '', $fileName) ?? $fileName;

        return self::guess(self::toSnakeCase($clean))['table'];
    }

    public static function toSnakeCase(string $value): string
    {
        $value = str_replace(['-', ' '], '_', trim($value));
        $value = Str::camelToUnderscore($value, '_');
        $value = preg_replace('/_+/', '_', $value) ?? $value;

        return strtolower(trim($value, '_'));
    }

    private static function normalizeOption(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = self::toSnakeCase($value);

        return $value !== '' ? $value : null;
    }

    private static function normalizeTable(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        if (str_ends_with($name, '_table')) {
            $name = substr($name, 0, -6);
        }

        $name = trim($name, '_');

        return $name !== '' ? $name : null;
    }

    private static function startsWithKnownVerb(string $snakeName): bool
    {
        foreach (self::KNOWN_VERBS as $verb) {
            if ($snakeName === $verb || str_starts_with($snakeName, $verb . '_')) {
                return true;
            }
        }

        return false;
    }
}
