<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_system_settings')) {
            return;
        }

        Schema::table('tbl_system_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('tbl_system_settings', 'enable_test_payments')) {
                $table->boolean('enable_test_payments')->default(false)->after('admin_alerts');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_system_settings')) {
            return;
        }

        Schema::table('tbl_system_settings', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_system_settings', 'enable_test_payments')) {
                $table->dropColumn('enable_test_payments');
            }
        });
    }
};
