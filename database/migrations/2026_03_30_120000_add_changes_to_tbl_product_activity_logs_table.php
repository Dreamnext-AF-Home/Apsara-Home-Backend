<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_product_activity_logs')) {
            return;
        }

        Schema::table('tbl_product_activity_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('tbl_product_activity_logs', 'pal_changes')) {
                $table->json('pal_changes')->nullable()->after('pal_actor_role');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_product_activity_logs')) {
            return;
        }

        Schema::table('tbl_product_activity_logs', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_product_activity_logs', 'pal_changes')) {
                $table->dropColumn('pal_changes');
            }
        });
    }
};
