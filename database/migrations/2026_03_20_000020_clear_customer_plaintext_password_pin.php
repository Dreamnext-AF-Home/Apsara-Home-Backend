<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tbl_customer') || ! Schema::hasColumn('tbl_customer', 'c_password_pin')) {
            return;
        }

        DB::table('tbl_customer')->update([
            'c_password_pin' => '',
        ]);
    }

    public function down(): void
    {
        // Intentionally irreversible: plaintext passwords must not be restored.
    }
};
