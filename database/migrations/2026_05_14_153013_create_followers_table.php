<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tbl_followers', function (Blueprint $table) {
            $table->id('follower_id');
            $table->unsignedBigInteger('user_id')->comment('The user who is following');
            $table->unsignedBigInteger('brand_id')->comment('The brand/merchant being followed');
            $table->boolean('is_active')->default(true)->comment('Whether the follow is active');
            $table->timestamp('followed_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Indexes
            $table->unique(['user_id', 'brand_id']);
            $table->index('brand_id');
            $table->index('followed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('followers');
    }
};
