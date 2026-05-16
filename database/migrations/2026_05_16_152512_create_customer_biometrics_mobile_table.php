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
        Schema::create('tbl_customer_biometrics_mobile', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cbm_customer_id')->index();
            $table->string('cbm_device_id', 255)->unique();
            $table->string('cbm_device_name', 255);
            $table->string('cbm_device_type', 50)->comment('ios, android');
            $table->text('cbm_credential_token');
            $table->text('cbm_public_key')->nullable();
            $table->timestamp('cbm_last_used_at')->nullable();
            $table->boolean('cbm_is_active')->default(true);
            $table->timestamps();

            $table->foreign('cbm_customer_id')
                ->references('c_userid')
                ->on('tbl_customer')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_customer_biometrics_mobile');
    }
};
