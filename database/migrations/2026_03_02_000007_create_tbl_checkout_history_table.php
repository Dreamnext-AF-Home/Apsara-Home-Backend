<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_checkout_history')) {
            return;
        }

        Schema::create('tbl_checkout_history', function (Blueprint $table) {
            $table->bigIncrements('ch_id');
            $table->unsignedBigInteger('ch_customer_id');
            $table->string('ch_checkout_id', 120)->unique();
            $table->string('ch_payment_intent_id', 120)->nullable();
            $table->string('ch_status', 40)->default('pending');
            $table->string('ch_description', 255)->nullable();
            $table->decimal('ch_amount', 12, 2)->default(0);
            $table->string('ch_payment_method', 40)->nullable();
            $table->integer('ch_quantity')->default(1);
            $table->string('ch_product_name', 255)->nullable();
            $table->text('ch_product_image')->nullable();
            $table->string('ch_selected_color', 100)->nullable();
            $table->string('ch_selected_size', 100)->nullable();
            $table->string('ch_selected_type', 100)->nullable();
            $table->string('ch_customer_name', 255)->nullable();
            $table->string('ch_customer_email', 255)->nullable();
            $table->string('ch_customer_phone', 50)->nullable();
            $table->text('ch_customer_address')->nullable();
            $table->timestamp('ch_paid_at')->nullable();
            $table->timestamps();

            $table->index(['ch_customer_id', 'created_at'], 'ch_customer_created_idx');
            $table->index('ch_status', 'ch_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_checkout_history');
    }
};
