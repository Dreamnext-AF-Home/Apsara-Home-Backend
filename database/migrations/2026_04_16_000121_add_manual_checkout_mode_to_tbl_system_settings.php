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
            if (!Schema::hasColumn('tbl_system_settings', 'enable_manual_checkout_mode')) {
                $table->boolean('enable_manual_checkout_mode')->default(false)->after('enable_test_payments');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_system_settings')) {
            return;
        }

        Schema::table('tbl_system_settings', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_system_settings', 'enable_manual_checkout_mode')) {
                $table->dropColumn('enable_manual_checkout_mode');
            }
        });
    }
};
