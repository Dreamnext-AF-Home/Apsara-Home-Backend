<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_order_notifications')) {
            Schema::create('tbl_order_notifications', function (Blueprint $table) {
                $table->bigIncrements('on_id');
                $table->unsignedBigInteger('on_customer_id');
                $table->string('on_checkout_id', 255)->nullable();
                $table->string('on_mobile_order_id', 100)->nullable();
                $table->string('on_type', 80)->default('order_created');
                $table->string('on_severity', 20)->default('info');
                $table->string('on_title', 255);
                $table->string('on_message', 500)->nullable();
                $table->string('on_product_name', 255)->nullable();
                $table->string('on_product_image', 1000)->nullable();
                $table->string('on_product_sku', 100)->nullable();
                $table->integer('on_quantity')->default(1);
                $table->decimal('on_amount', 12, 2)->default(0);
                $table->string('on_status', 50)->default('pending');
                $table->string('on_payment_method', 50)->nullable();
                $table->string('on_href', 500)->nullable();
                $table->json('on_payload')->nullable();
                $table->boolean('on_is_read')->default(false);
                $table->timestamp('on_read_at')->nullable();
                $table->timestamp('on_created_at')->nullable();

                $table->index('on_customer_id', 'idx_on_customer');
                $table->index('on_checkout_id', 'idx_on_checkout');
                $table->index('on_mobile_order_id', 'idx_on_mobile_order');
                $table->index('on_is_read', 'idx_on_is_read');
                $table->index('on_created_at', 'idx_on_created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_order_notifications');
    }
};
