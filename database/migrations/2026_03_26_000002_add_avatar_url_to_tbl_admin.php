<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_admin') && ! Schema::hasColumn('tbl_admin', 'avatar_url')) {
            Schema::table('tbl_admin', function (Blueprint $table) {
                $table->string('avatar_url', 1200)->nullable()->after('fname');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_admin') && Schema::hasColumn('tbl_admin', 'avatar_url')) {
            Schema::table('tbl_admin', function (Blueprint $table) {
                $table->dropColumn('avatar_url');
            });
        }
    }
};
