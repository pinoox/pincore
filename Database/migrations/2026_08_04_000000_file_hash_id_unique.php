<?php

/**
 * @author   Pinoox
 * @link https://www.pinoox.com
 * @license  https://opensource.org/licenses/MIT MIT License
 */

namespace Pinoox\Database\migrations;

use Illuminate\Database\Schema\Blueprint;
use Pinoox\Component\Migration\MigrationBase;
use Pinoox\Model\Table;
use Pinoox\Portal\Database\DB;

return new class extends MigrationBase {
    public function up(): void
    {
        $table = $this->table(Table::FILE, 'platform');

        $this->backfillHashIds($table);

        if (!$this->schema->hasIndex($table, $table . '_hash_id_unique')) {
            $this->schema->table($table, function (Blueprint $blueprint) {
                $blueprint->unique('hash_id');
            });
        }

        if (!$this->schema->hasIndex($table, $table . '_app_file_access_index')) {
            $this->schema->table($table, function (Blueprint $blueprint) {
                $blueprint->index(['app', 'file_access']);
            });
        }
    }

    public function down(): void
    {
        $table = $this->table(Table::FILE, 'platform');

        $this->schema->table($table, function (Blueprint $blueprint) use ($table) {
            if ($this->schema->hasIndex($table, $table . '_hash_id_unique')) {
                $blueprint->dropUnique(['hash_id']);
            }

            if ($this->schema->hasIndex($table, $table . '_app_file_access_index')) {
                $blueprint->dropIndex(['app', 'file_access']);
            }
        });
    }

    private function backfillHashIds(string $table): void
    {
        DB::table($table, null, 'platform')
            ->where(function ($q) {
                $q->whereNull('hash_id')->orWhere('hash_id', '');
            })
            ->orderBy('file_id')
            ->chunk(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    $hash = null;
                    for ($i = 0; $i < 16; $i++) {
                        $candidate = substr(bin2hex(random_bytes(4)), 0, 8);
                        $exists = DB::table($table, null, 'platform')
                            ->where('hash_id', $candidate)
                            ->exists();
                        if (!$exists) {
                            $hash = $candidate;
                            break;
                        }
                    }

                    DB::table($table, null, 'platform')
                        ->where('file_id', $row->file_id)
                        ->update(['hash_id' => $hash ?? substr(bin2hex(random_bytes(4)), 0, 8)]);
                }
            });
    }
};
