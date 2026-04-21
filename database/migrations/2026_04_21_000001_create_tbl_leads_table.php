<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_leads')) {
            return;
        }

        Schema::create('tbl_leads', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->nullable();
            $table->text('address')->nullable();
            $table->string('website', 500)->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('description')->nullable();
            $table->decimal('rating', 2, 1)->nullable();
            $table->integer('reviews')->nullable();
            $table->string('category', 255)->nullable();
            $table->text('keywords')->nullable();
            $table->string('price_level', 10)->nullable();
            $table->json('opening_hours')->nullable();
            $table->text('email')->nullable();
            $table->string('facebook', 500)->nullable();
            $table->string('twitter', 500)->nullable();
            $table->string('instagram', 500)->nullable();
            $table->string('contact', 500)->nullable();
            $table->string('search_query', 255)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('searched', 5)->default('NO');
            $table->timestamp('created_at')->useCurrent();

            $table->index('category');
            $table->index('search_query');
            $table->index('location');
            $table->index('searched');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_leads');
    }
};
