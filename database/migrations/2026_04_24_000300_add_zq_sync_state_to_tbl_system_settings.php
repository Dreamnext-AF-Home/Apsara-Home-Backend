<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tbl_system_settings')) {
            return;
        }

        Schema::table('tbl_system_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_system_settings', 'zq_saved_cursor')) {
                $table->string('zq_saved_cursor')->nullable()->after('enable_manual_checkout_mode');
            }

            if (! Schema::hasColumn('tbl_system_settings', 'zq_last_synced_at')) {
                $table->timestamp('zq_last_synced_at')->nullable()->after('zq_saved_cursor');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tbl_system_settings')) {
            return;
        }

        Schema::table('tbl_system_settings', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_system_settings', 'zq_last_synced_at')) {
                $table->dropColumn('zq_last_synced_at');
            }

            if (Schema::hasColumn('tbl_system_settings', 'zq_saved_cursor')) {
                $table->dropColumn('zq_saved_cursor');
            }
        });
    }
};
