<?php

namespace Pinoox\Database\migrations;

use Illuminate\Database\Schema\Blueprint;
use Pinoox\Component\Migration\MigrationBase;
use Pinoox\Model\Table;

return new class extends MigrationBase {
    public function up(): void
    {
        if ($this->schema->hasTable(Table::FILE) && !$this->schema->hasColumn(Table::FILE, 'file_disk')) {
            $this->schema->table(Table::FILE, function (Blueprint $table) {
                $table->string('file_disk', 64)->nullable()->after('file_access');
                $table->index('file_disk');
            });
        }
    }

    public function down(): void
    {
        if ($this->schema->hasTable(Table::FILE) && $this->schema->hasColumn(Table::FILE, 'file_disk')) {
            $this->schema->table(Table::FILE, function (Blueprint $table) {
                $table->dropIndex(['file_disk']);
                $table->dropColumn('file_disk');
            });
        }
    }
};
