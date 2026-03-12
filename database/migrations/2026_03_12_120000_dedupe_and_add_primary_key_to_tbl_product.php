<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_product') || !Schema::hasColumn('tbl_product', 'pd_id')) {
            return;
        }

        DB::transaction(function () {
            DB::statement(<<<'SQL'
                DELETE FROM tbl_product a
                USING tbl_product b
                WHERE a.ctid < b.ctid
                  AND a.pd_id = b.pd_id
            SQL);

            $primaryKeyExists = DB::table('information_schema.table_constraints')
                ->where('table_schema', 'public')
                ->where('table_name', 'tbl_product')
                ->where('constraint_type', 'PRIMARY KEY')
                ->exists();

            if (! $primaryKeyExists) {
                DB::statement('ALTER TABLE tbl_product ADD PRIMARY KEY (pd_id)');
            }

            DB::statement(<<<'SQL'
                SELECT setval(
                    pg_get_serial_sequence('tbl_product', 'pd_id'),
                    COALESCE((SELECT MAX(pd_id) FROM tbl_product), 1),
                    true
                )
            SQL);
        });
    }

    public function down(): void
    {
        // Duplicate rows cannot be restored safely.
    }
};
