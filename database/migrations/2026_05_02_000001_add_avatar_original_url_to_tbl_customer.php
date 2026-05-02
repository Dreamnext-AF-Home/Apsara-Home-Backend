<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_customer') && ! Schema::hasColumn('tbl_customer', 'c_avatar_original_url')) {
            Schema::table('tbl_customer', function (Blueprint $table) {
                $table->string('c_avatar_original_url', 1200)->nullable()->after('c_avatar_url');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_customer') && Schema::hasColumn('tbl_customer', 'c_avatar_original_url')) {
            Schema::table('tbl_customer', function (Blueprint $table) {
                $table->dropColumn('c_avatar_original_url');
            });
        }
    }
};
