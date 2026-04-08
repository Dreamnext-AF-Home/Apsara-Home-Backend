<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_product_reviews')) {
            return;
        }

        Schema::create('tbl_product_reviews', function (Blueprint $table) {
            $table->bigIncrements('pr_id');
            $table->unsignedBigInteger('pr_product_id');
            $table->unsignedBigInteger('pr_customer_id');
            $table->unsignedBigInteger('pr_order_id')->nullable();
            $table->unsignedTinyInteger('pr_rating');
            $table->text('pr_review');
            $table->timestamps();

            $table->index('pr_product_id');
            $table->index('pr_customer_id');
            $table->index('pr_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_product_reviews');
    }
};
