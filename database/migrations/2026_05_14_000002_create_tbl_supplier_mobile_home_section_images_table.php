<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_supplier_mobile_home_section_images')) {
            return;
        }

        Schema::create('tbl_supplier_mobile_home_section_images', function (Blueprint $table) {
            $table->bigIncrements('smhsi_id');
            $table->unsignedBigInteger('smhsi_section_id');
            $table->text('smhsi_image_url');
            $table->integer('smhsi_image_order')->default(0);
            $table->string('smhsi_link_type', 50)->nullable(); // product, category, discount, external-url, none
            $table->string('smhsi_link_target', 500)->nullable(); // product ID, category ID, discount code, or URL
            $table->string('smhsi_alt_text', 255)->nullable();
            $table->timestamps();

            $table->index(['smhsi_section_id', 'smhsi_image_order'], 'smhsi_section_order_idx');
            $table->foreign('smhsi_section_id')
                ->references('smhs_id')
                ->on('tbl_supplier_mobile_home_sections')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_supplier_mobile_home_section_images');
    }
};
