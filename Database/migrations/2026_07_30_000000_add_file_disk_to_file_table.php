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

        if (!$this->schema->hasColumn($table, 'file_disk')) {
            $this->schema->table($table, function (Blueprint $blueprint) {
                $blueprint->string('file_disk', 64)->nullable()->after('file_access');
                $blueprint->index('file_disk');
            });
        }

        $this->backfillDiskFromMetadata($table);
    }

    public function down(): void
    {
        $table = $this->table(Table::FILE, 'platform');

        if ($this->schema->hasColumn($table, 'file_disk')) {
            $this->schema->table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex(['file_disk']);
                $blueprint->dropColumn('file_disk');
            });
        }
    }

    private function backfillDiskFromMetadata(string $table): void
    {
        $rows = DB::table($table, null, 'platform')
            ->whereNull('file_disk')
            ->whereNotNull('file_metadata')
            ->orderBy('file_id')
            ->get(['file_id', 'file_metadata']);

        foreach ($rows as $row) {
            $meta = $row->file_metadata;
            if (is_string($meta)) {
                $meta = json_decode($meta, true);
            }
            if (!is_array($meta) || empty($meta['disk']) || !is_string($meta['disk'])) {
                continue;
            }

            $disk = trim($meta['disk']);
            if ($disk === '') {
                continue;
            }

            DB::table($table, null, 'platform')
                ->where('file_id', $row->file_id)
                ->update(['file_disk' => $disk]);
        }
    }
};
