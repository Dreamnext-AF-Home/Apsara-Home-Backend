<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optimize cart table for duplicate checking
        if (Schema::hasTable('tbl_add_to_cart')) {
            Schema::table('tbl_add_to_cart', function (Blueprint $table) {
                // Composite index for fast duplicate checking in addToCart
                $table->index(
                    ['crt_customer_id', 'crt_product_id', 'crt_variant_id', 'crt_selected_color', 'crt_selected_size', 'crt_selected_type', 'crt_status'],
                    'idx_cart_duplicate_check'
                );
                
                // Index for total cart items calculation
                $table->index(['crt_customer_id', 'crt_status'], 'idx_cart_customer_status_total');
            });
        }

        // Optimize checkout history for sold count queries
        if (Schema::hasTable('tbl_checkout_history')) {
            Schema::table('tbl_checkout_history', function (Blueprint $table) {
                $table->index('ch_product_id', 'idx_checkout_product_id');
                $table->index(['ch_product_id', 'ch_status'], 'idx_checkout_product_status');
            });
        }

        // Optimize product table for search queries
        if (Schema::hasTable('tbl_product')) {
            Schema::table('tbl_product', function (Blueprint $table) {
                // Index for search queries
                $table->index(['pd_status', 'pd_bestseller', 'pd_musthave'], 'idx_product_status_flags');
                $table->index('pd_name', 'idx_product_name');
                $table->index('pd_brand_type', 'idx_product_brand_type');
                
                // Composite index for live search
                $table->index(
                    ['pd_status', 'pd_bestseller', 'pd_musthave', 'pd_name'],
                    'idx_product_live_search'
                );
            });
        }

        // Optimize product variant table
        if (Schema::hasTable('tbl_product_variant')) {
            Schema::table('tbl_product_variant', function (Blueprint $table) {
                $table->index(['pv_pdid', 'pv_status'], 'idx_variant_product_status');
                $table->index('pv_status', 'idx_variant_status');
            });
        }

        // Optimize search history table
        if (Schema::hasTable('tbl_search_history')) {
            Schema::table('tbl_search_history', function (Blueprint $table) {
                $table->index(['sh_customer_id', 'sh_date_created'], 'idx_search_customer_date');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_add_to_cart')) {
            Schema::table('tbl_add_to_cart', function (Blueprint $table) {
                $table->dropIndex('idx_cart_duplicate_check');
                $table->dropIndex('idx_cart_customer_status_total');
            });
        }

        if (Schema::hasTable('tbl_checkout_history')) {
            Schema::table('tbl_checkout_history', function (Blueprint $table) {
                $table->dropIndex('idx_checkout_product_id');
                $table->dropIndex('idx_checkout_product_status');
            });
        }

        if (Schema::hasTable('tbl_product')) {
            Schema::table('tbl_product', function (Blueprint $table) {
                $table->dropIndex('idx_product_status_flags');
                $table->dropIndex('idx_product_name');
                $table->dropIndex('idx_product_brand_type');
                $table->dropIndex('idx_product_live_search');
            });
        }

        if (Schema::hasTable('tbl_product_variant')) {
            Schema::table('tbl_product_variant', function (Blueprint $table) {
                $table->dropIndex('idx_variant_product_status');
                $table->dropIndex('idx_variant_status');
            });
        }

        if (Schema::hasTable('tbl_search_history')) {
            Schema::table('tbl_search_history', function (Blueprint $table) {
                $table->dropIndex('idx_search_customer_date');
            });
        }
    }
};
