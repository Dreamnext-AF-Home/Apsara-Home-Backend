<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_checkout_history', function (Blueprint $table) {
            // Mobile-specific identifiers
            $table->string('ch_mobile_order_id', 50)->nullable()->unique()->after('ch_checkout_id');
            
            // Mobile platform info
            $table->enum('ch_platform', ['ios', 'android'])->nullable()->after('ch_payment_intent_id');
            $table->string('ch_app_version', 50)->nullable()->after('ch_platform');
            $table->string('ch_device_id', 255)->nullable()->after('ch_app_version');
            
            // Mobile metadata
            $table->boolean('ch_is_mobile')->default(false)->after('ch_device_id');
            $table->json('ch_mobile_metadata')->nullable()->after('ch_is_mobile');
            
            // Indexes for performance
            $table->index(['ch_customer_id', 'ch_is_mobile'], 'idx_customer_mobile');
            $table->index(['ch_platform', 'ch_status'], 'idx_platform_status');
            $table->index('ch_mobile_order_id', 'idx_mobile_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_checkout_history', function (Blueprint $table) {
            $table->dropIndex('idx_customer_mobile');
            $table->dropIndex('idx_platform_status');
            $table->dropIndex('idx_mobile_order_id');
            
            $table->dropColumn([
                'ch_mobile_order_id',
                'ch_platform',
                'ch_app_version',
                'ch_device_id',
                'ch_is_mobile',
                'ch_mobile_metadata'
            ]);
        });
    }
};
