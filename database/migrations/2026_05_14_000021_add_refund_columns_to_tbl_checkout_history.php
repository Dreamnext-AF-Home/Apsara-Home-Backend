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

        Schema::table('tbl_checkout_history', function (Blueprint $table): void {
            if (!Schema::hasColumn('tbl_checkout_history', 'ch_refund_reason')) {
                $table->text('ch_refund_reason')->nullable()->after('ch_shipment_payload');
            }
            if (!Schema::hasColumn('tbl_checkout_history', 'ch_refund_image_urls')) {
                $table->json('ch_refund_image_urls')->nullable()->after('ch_refund_reason');
            }
            if (!Schema::hasColumn('tbl_checkout_history', 'ch_refund_video_urls')) {
                $table->json('ch_refund_video_urls')->nullable()->after('ch_refund_image_urls');
            }
            if (!Schema::hasColumn('tbl_checkout_history', 'ch_refund_requested_at')) {
                $table->timestamp('ch_refund_requested_at')->nullable()->after('ch_refund_video_urls');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_checkout_history')) {
            return;
        }

        Schema::table('tbl_checkout_history', function (Blueprint $table): void {
            foreach ([
                'ch_refund_requested_at',
                'ch_refund_video_urls',
                'ch_refund_image_urls',
                'ch_refund_reason',
            ] as $column) {
                if (Schema::hasColumn('tbl_checkout_history', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

