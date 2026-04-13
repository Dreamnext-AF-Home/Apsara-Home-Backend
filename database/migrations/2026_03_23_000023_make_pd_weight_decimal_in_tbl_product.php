<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite' || !Schema::hasTable('tbl_product') || !Schema::hasColumn('tbl_product', 'pd_weight')) {
            return;
        }

        DB::statement('ALTER TABLE tbl_product ALTER COLUMN pd_weight TYPE numeric(12,2) USING pd_weight::numeric(12,2)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite' || !Schema::hasTable('tbl_product') || !Schema::hasColumn('tbl_product', 'pd_weight')) {
            return;
        }

        DB::statement('ALTER TABLE tbl_product ALTER COLUMN pd_weight TYPE smallint USING ROUND(pd_weight)::smallint');
    }
};
