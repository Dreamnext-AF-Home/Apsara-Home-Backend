<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tbl_product_brand ADD COLUMN IF NOT EXISTS pb_image varchar(1000) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tbl_product_brand DROP COLUMN IF EXISTS pb_image');
    }
};
