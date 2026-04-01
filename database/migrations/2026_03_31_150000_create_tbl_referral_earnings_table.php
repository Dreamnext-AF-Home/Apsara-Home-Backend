<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_referral_earnings')) {
            return;
        }

        Schema::create('tbl_referral_earnings', function (Blueprint $table) {
            $table->bigIncrements('re_id');
            $table->unsignedBigInteger('re_order_id')->nullable();
            $table->string('re_checkout_id', 120)->nullable();
            $table->unsignedBigInteger('re_buyer_customer_id')->nullable();
            $table->unsignedBigInteger('re_referrer_customer_id');
            $table->unsignedBigInteger('re_product_id')->nullable();
            $table->string('re_product_sku', 120)->nullable();
            $table->integer('re_quantity')->default(1);
            $table->decimal('re_order_amount', 12, 2)->default(0);
            $table->decimal('re_commission_basis_amount', 12, 2)->default(0);
            $table->decimal('re_amount', 12, 2)->default(0);
            $table->string('re_status', 30)->default('pending');
            $table->string('re_source_type', 50)->nullable();
            $table->string('re_reference_no', 120)->nullable();
            $table->text('re_notes')->nullable();
            $table->timestamp('re_available_at')->nullable();
            $table->unsignedBigInteger('re_released_by')->nullable();
            $table->timestamp('re_released_at')->nullable();
            $table->unsignedBigInteger('re_cancelled_by')->nullable();
            $table->timestamp('re_cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['re_order_id', 're_referrer_customer_id'], 're_order_referrer_unique');
            $table->index(['re_referrer_customer_id', 're_status'], 're_referrer_status_idx');
            $table->index(['re_checkout_id'], 're_checkout_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_referral_earnings');
    }
};
