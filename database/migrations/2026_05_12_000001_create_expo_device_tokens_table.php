<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_expo_device_tokens')) {
            Schema::create('tbl_expo_device_tokens', function (Blueprint $table) {
                $table->bigIncrements('edt_id');
                $table->unsignedBigInteger('edt_customer_id');
                $table->string('edt_token', 255);
                $table->string('edt_device_name', 255)->nullable();
                $table->string('edt_platform', 20)->default('ios');
                $table->boolean('edt_is_active')->default(true);
                $table->timestamp('edt_created_at')->nullable();
                $table->timestamp('edt_updated_at')->nullable();

                $table->unique(['edt_customer_id', 'edt_token'], 'unique_customer_token');
                $table->index('edt_customer_id', 'idx_edt_customer');
                $table->index('edt_is_active', 'idx_edt_is_active');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_expo_device_tokens');
    }
};
