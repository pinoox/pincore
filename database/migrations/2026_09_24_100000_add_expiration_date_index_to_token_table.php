<?php

namespace Pinoox\Database\migrations;

use Illuminate\Database\Schema\Blueprint;
use Pinoox\Component\Migration\MigrationBase;
use Pinoox\Model\Table;

return new class extends MigrationBase {
    public function up(): void
    {
        if ($this->schema->hasTable(Table::TOKEN) && $this->schema->hasColumn(Table::TOKEN, 'expiration_date')) {
            $this->schema->table(Table::TOKEN, function (Blueprint $table) {
                $table->index('expiration_date');
            });
        }
    }

    public function down(): void
    {
        if ($this->schema->hasTable(Table::TOKEN) && $this->schema->hasColumn(Table::TOKEN, 'expiration_date')) {
            $this->schema->table(Table::TOKEN, function (Blueprint $table) {
                $table->dropIndex(['expiration_date']);
            });
        }
    }
};
