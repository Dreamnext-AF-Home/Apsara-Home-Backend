<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_onesignal_device_tokens')) {
            Schema::create('tbl_onesignal_device_tokens', function (Blueprint $table) {
                $table->bigIncrements('odt_id');
                $table->unsignedBigInteger('odt_customer_id');
                $table->string('odt_player_id', 255);
                $table->string('odt_device_name', 255)->nullable();
                $table->string('odt_platform', 20)->default('ios');
                $table->boolean('odt_is_active')->default(true);
                $table->timestamp('odt_created_at')->nullable();
                $table->timestamp('odt_updated_at')->nullable();

                $table->unique(['odt_customer_id', 'odt_player_id'], 'unique_customer_player_id');
                $table->index('odt_customer_id', 'idx_odt_customer');
                $table->index('odt_is_active', 'idx_odt_is_active');

                // Foreign key constraint
                $table->foreign('odt_customer_id', 'fk_odt_customer_id')
                    ->references('c_userid')
                    ->on('tbl_customers')
                    ->onDelete('cascade')
                    ->onUpdate('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_onesignal_device_tokens');
    }
};
