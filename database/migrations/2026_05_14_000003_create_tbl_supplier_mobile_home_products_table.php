<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_supplier_mobile_home_products')) {
            return;
        }

        Schema::create('tbl_supplier_mobile_home_products', function (Blueprint $table) {
            $table->bigIncrements('smhp_id');
            $table->unsignedBigInteger('smhp_section_id');
            $table->unsignedBigInteger('smhp_product_id');
            $table->integer('smhp_product_order')->default(0);
            $table->timestamps();

            $table->index(['smhp_section_id', 'smhp_product_order'], 'smhp_section_order_idx');
            $table->unique(['smhp_section_id', 'smhp_product_id'], 'smhp_section_product_unique');
            $table->foreign('smhp_section_id')
                ->references('smhs_id')
                ->on('tbl_supplier_mobile_home_sections')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_supplier_mobile_home_products');
    }
};
