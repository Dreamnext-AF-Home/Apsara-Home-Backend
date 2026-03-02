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
            if (!Schema::hasColumn('tbl_checkout_history', 'ch_approval_status')) {
                $table->string('ch_approval_status', 30)->default('pending_approval')->after('ch_status');
            }

            if (!Schema::hasColumn('tbl_checkout_history', 'ch_approval_notes')) {
                $table->text('ch_approval_notes')->nullable()->after('ch_approval_status');
            }

            if (!Schema::hasColumn('tbl_checkout_history', 'ch_approved_by')) {
                $table->unsignedBigInteger('ch_approved_by')->nullable()->after('ch_approval_notes');
            }

            if (!Schema::hasColumn('tbl_checkout_history', 'ch_approved_at')) {
                $table->timestamp('ch_approved_at')->nullable()->after('ch_approved_by');
            }

            if (!Schema::hasColumn('tbl_checkout_history', 'ch_fulfillment_status')) {
                $table->string('ch_fulfillment_status', 40)->default('pending')->after('ch_approved_at');
            }
        });

        Schema::table('tbl_checkout_history', function (Blueprint $table) {
            $table->index('ch_approval_status', 'ch_approval_status_idx');
            $table->index('ch_fulfillment_status', 'ch_fulfillment_status_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_checkout_history')) {
            return;
        }

        Schema::table('tbl_checkout_history', function (Blueprint $table) {
            foreach ([
                'ch_approval_status_idx',
                'ch_fulfillment_status_idx',
            ] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (\Throwable) {
                }
            }

            foreach ([
                'ch_fulfillment_status',
                'ch_approved_at',
                'ch_approved_by',
                'ch_approval_notes',
                'ch_approval_status',
            ] as $column) {
                if (Schema::hasColumn('tbl_checkout_history', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
