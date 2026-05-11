<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add index for faster PV lookups on customer and posted status
        if (Schema::hasTable('tbl_checkout_history')) {
            Schema::table('tbl_checkout_history', function (Blueprint $table) {
                // Drop if exists first, then create
                try {
                    $table->dropIndex('ch_customer_pv_posted_idx');
                } catch (\Throwable $e) {
                    // Index doesn't exist, ignore
                }
                $table->index(['ch_customer_id', 'ch_pv_posted_at'], 'ch_customer_pv_posted_idx');
            });
        }

        // Ensure customer sponsorship lookups are fast
        if (Schema::hasTable('tbl_customers')) {
            Schema::table('tbl_customers', function (Blueprint $table) {
                try {
                    $table->dropIndex('c_sponsor_idx');
                } catch (\Throwable $e) {
                    // Index doesn't exist, ignore
                }
                $table->index('c_sponsor', 'c_sponsor_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_checkout_history')) {
            Schema::table('tbl_checkout_history', function (Blueprint $table) {
                $table->dropIndexIfExists('ch_customer_pv_posted_idx');
            });
        }

        if (Schema::hasTable('tbl_customers')) {
            Schema::table('tbl_customers', function (Blueprint $table) {
                $table->dropIndexIfExists('c_sponsor_idx');
            });
        }
    }
};
