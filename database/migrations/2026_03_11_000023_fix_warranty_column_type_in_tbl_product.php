<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Change pd_warranty from numeric to varchar(255)
        DB::statement('ALTER TABLE tbl_product ALTER COLUMN pd_warranty TYPE varchar(255) USING pd_warranty::varchar');
    }

    public function down(): void
    {
        // Revert back to numeric (lossy, but needed for rollback)
        DB::statement('ALTER TABLE tbl_product ALTER COLUMN pd_warranty TYPE numeric USING NULL');
    }
};
