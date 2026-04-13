<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_product_brand')) {
            return;
        }

        DB::statement('ALTER TABLE tbl_product_brand ADD COLUMN IF NOT EXISTS pb_image varchar(1000) NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_product_brand')) {
            return;
        }

        DB::statement('ALTER TABLE tbl_product_brand DROP COLUMN IF EXISTS pb_image');
    }
};
