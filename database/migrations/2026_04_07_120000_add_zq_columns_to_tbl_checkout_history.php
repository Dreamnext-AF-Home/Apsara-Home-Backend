<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_checkout_history')) {
            return;
        }

        Schema::table('tbl_checkout_history', function (Blueprint $table) {
            if (!Schema::hasColumn('tbl_checkout_history', 'ch_zq_platform_order_id')) {
                $table->string('ch_zq_platform_order_id', 120)->nullable()->after('ch_shipped_at');
            }
            if (!Schema::hasColumn('tbl_checkout_history', 'ch_zq_order_id')) {
                $table->string('ch_zq_order_id', 120)->nullable()->after('ch_zq_platform_order_id');
            }
            if (!Schema::hasColumn('tbl_checkout_history', 'ch_zq_status')) {
                $table->string('ch_zq_status', 60)->nullable()->after('ch_zq_order_id');
            }
            if (!Schema::hasColumn('tbl_checkout_history', 'ch_zq_payload')) {
                $table->json('ch_zq_payload')->nullable()->after('ch_zq_status');
            }
            if (!Schema::hasColumn('tbl_checkout_history', 'ch_zq_response')) {
                $table->json('ch_zq_response')->nullable()->after('ch_zq_payload');
            }
            if (!Schema::hasColumn('tbl_checkout_history', 'ch_zq_synced_at')) {
                $table->timestamp('ch_zq_synced_at')->nullable()->after('ch_zq_response');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_checkout_history')) {
            return;
        }

        Schema::table('tbl_checkout_history', function (Blueprint $table) {
            foreach ([
                'ch_zq_synced_at',
                'ch_zq_response',
                'ch_zq_payload',
                'ch_zq_status',
                'ch_zq_order_id',
                'ch_zq_platform_order_id',
            ] as $column) {
                if (Schema::hasColumn('tbl_checkout_history', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
