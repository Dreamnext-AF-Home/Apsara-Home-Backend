<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_admin') && ! Schema::hasColumn('tbl_admin', 'admin_permissions')) {
            Schema::table('tbl_admin', function (Blueprint $table) {
                $table->json('admin_permissions')->nullable()->after('supplier_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_admin') && Schema::hasColumn('tbl_admin', 'admin_permissions')) {
            Schema::table('tbl_admin', function (Blueprint $table) {
                $table->dropColumn('admin_permissions');
            });
        }
    }
};
