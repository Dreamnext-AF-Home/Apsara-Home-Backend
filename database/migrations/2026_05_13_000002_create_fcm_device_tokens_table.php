<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_fcm_device_tokens')) {
            Schema::create('tbl_fcm_device_tokens', function (Blueprint $table) {
                $table->bigIncrements('fdt_id');
                $table->unsignedBigInteger('fdt_customer_id');
                $table->string('fdt_fcm_token', 500);
                $table->string('fdt_device_name', 255)->nullable();
                $table->string('fdt_platform', 20)->default('ios');
                $table->boolean('fdt_is_active')->default(true);
                $table->timestamp('fdt_created_at')->nullable();
                $table->timestamp('fdt_updated_at')->nullable();

                $table->unique(['fdt_customer_id', 'fdt_fcm_token'], 'unique_customer_fcm_token');
                $table->index('fdt_customer_id', 'idx_fdt_customer');
                $table->index('fdt_is_active', 'idx_fdt_is_active');

                // Foreign key constraint
                $table->foreign('fdt_customer_id', 'fk_fdt_customer_id')
                    ->references('c_userid')
                    ->on('tbl_customers')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_fcm_device_tokens');
    }
};
