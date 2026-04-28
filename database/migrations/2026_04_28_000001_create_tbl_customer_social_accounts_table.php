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
        if (Schema::hasTable('tbl_customer_social_accounts')) {
            return;
        }

        Schema::create('tbl_customer_social_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('csa_customer_id');
            $table->string('csa_provider', 50); // 'google', 'facebook', etc.
            $table->string('csa_provider_id', 255)->unique();
            $table->string('csa_token')->nullable();
            $table->string('csa_refresh_token')->nullable();
            $table->timestamp('csa_token_expires_at')->nullable();
            $table->json('csa_provider_data')->nullable();
            $table->timestamps();

            $table->foreign('csa_customer_id')
                ->references('c_userid')
                ->on('tbl_customer')
                ->onDelete('cascade');

            $table->index('csa_customer_id');
            $table->index('csa_provider');
            $table->unique(['csa_provider', 'csa_provider_id'], 'unique_provider_account');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_customer_social_accounts');
    }
};
