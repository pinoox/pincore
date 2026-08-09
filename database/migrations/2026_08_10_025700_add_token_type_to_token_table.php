<?php

namespace Pinoox\Database\migrations;

use Illuminate\Database\Schema\Blueprint;
use Pinoox\Component\Migration\MigrationBase;
use Pinoox\Model\Table;
use Pinoox\Portal\Database\DB;

return new class extends MigrationBase {
    public function up(): void
    {
        if (!$this->schema->hasColumn(Table::TOKEN, 'token_type')) {
            $this->schema->table(Table::TOKEN, function (Blueprint $table) {
                $table->string('token_type', 50)->default('auth');
                $table->index('token_type');
                $table->index(['user_id', 'token_type']);
            });
        }

        DB::table(Table::TOKEN, null, 'platform')
            ->where(function ($query) {
                $query->whereNull('token_type')->orWhere('token_type', '');
            })
            ->update(['token_type' => 'auth']);
    }

    public function down(): void
    {
        if (!$this->schema->hasColumn(Table::TOKEN, 'token_type')) {
            return;
        }

        $this->schema->table(Table::TOKEN, function (Blueprint $table) {
            $table->dropColumn('token_type');
        });
    }
};
