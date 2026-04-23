<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_zqproducts')) {
            return;
        }

        Schema::create('tbl_zqproducts', function (Blueprint $table) {
            $table->bigIncrements('zqp_id');
            $table->string('zqp_external_id', 64)->unique();
            $table->string('zqp_offer_id', 64)->nullable();
            $table->string('zqp_category_id', 64)->nullable();
            $table->string('zqp_category_name', 255)->nullable();
            $table->text('zqp_subject');
            $table->text('zqp_subject_cn')->nullable();
            $table->longText('zqp_description')->nullable();
            $table->json('zqp_images')->nullable();
            $table->string('zqp_primary_image', 1000)->nullable();
            $table->json('zqp_specs')->nullable();
            $table->string('zqp_source_type', 80)->nullable()->index();
            $table->string('zqp_status', 80)->nullable()->index();
            $table->string('zqp_import_status', 80)->nullable()->index();
            $table->string('zqp_product_url', 1000)->nullable();
            $table->string('zqp_target_currency', 24)->nullable();
            $table->string('zqp_shipping_to', 24)->nullable();
            $table->timestampTz('zqp_published_at')->nullable();
            $table->timestampTz('zqp_source_created_at')->nullable();
            $table->timestampTz('zqp_source_updated_at')->nullable();
            $table->bigInteger('zqp_price_min_cents')->nullable();
            $table->bigInteger('zqp_price_max_cents')->nullable();
            $table->bigInteger('zqp_cost_min_cents')->nullable();
            $table->bigInteger('zqp_cost_max_cents')->nullable();
            $table->integer('zqp_total_stock')->default(0);
            $table->integer('zqp_variant_count')->default(0);
            $table->json('zqp_raw_payload')->nullable();
            $table->timestamps();

            $table->index(['zqp_source_type', 'zqp_import_status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_zqproducts');
    }
};
