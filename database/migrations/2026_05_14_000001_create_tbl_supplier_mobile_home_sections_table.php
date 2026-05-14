<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_supplier_mobile_home_sections')) {
            return;
        }

        Schema::create('tbl_supplier_mobile_home_sections', function (Blueprint $table) {
            $table->bigIncrements('smhs_id');
            $table->unsignedBigInteger('smhs_supplier_id');
            $table->string('smhs_section_type', 50); // carousel, banner, product-grid, categories, text, etc.
            $table->string('smhs_section_title', 255)->nullable();
            $table->integer('smhs_section_order')->default(0);
            $table->boolean('smhs_is_active')->default(true);
            $table->json('smhs_config')->nullable(); // stores section-specific settings
            $table->timestamps();

            $table->index(['smhs_supplier_id', 'smhs_is_active'], 'smhs_supplier_active_idx');
            $table->index(['smhs_supplier_id', 'smhs_section_order'], 'smhs_supplier_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_supplier_mobile_home_sections');
    }
};
