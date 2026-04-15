<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_add_to_cart')) {
            return;
        }

        Schema::create('tbl_add_to_cart', function (Blueprint $table) {
            $table->bigIncrements('crt_id');
            $table->unsignedBigInteger('crt_customer_id');
            $table->unsignedBigInteger('crt_product_id');
            $table->unsignedBigInteger('crt_variant_id')->nullable();
            $table->integer('crt_quantity')->default(1);
            $table->string('crt_selected_color', 100)->nullable();
            $table->string('crt_selected_size', 100)->nullable();
            $table->string('crt_selected_type', 100)->nullable();
            $table->decimal('crt_unit_price', 12, 2)->default(0);
            $table->decimal('crt_total_price', 12, 2)->default(0);
            $table->string('crt_status', 20)->default('active');
            $table->timestamp('crt_created_at')->nullable();
            $table->timestamp('crt_updated_at')->nullable();

            $table->index('crt_customer_id', 'crt_customer_idx');
            $table->index('crt_product_id', 'crt_product_idx');
            $table->index(['crt_customer_id', 'crt_status'], 'crt_customer_status_idx');
            $table->index('crt_status', 'crt_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_add_to_cart');
    }
};
