<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite' || !Schema::hasTable('tbl_product') || !Schema::hasColumn('tbl_product', 'pd_warranty')) {
            return;
        }

        DB::statement('ALTER TABLE tbl_product ALTER COLUMN pd_warranty DROP NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite' || !Schema::hasTable('tbl_product') || !Schema::hasColumn('tbl_product', 'pd_warranty')) {
            return;
        }

        DB::statement('ALTER TABLE tbl_product ALTER COLUMN pd_warranty SET NOT NULL');
    }
};
