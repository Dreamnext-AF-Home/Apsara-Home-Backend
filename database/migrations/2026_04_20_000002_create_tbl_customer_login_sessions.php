<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_customer_login_sessions')) {
            return;
        }

        Schema::create('tbl_customer_login_sessions', function (Blueprint $table) {
            $table->bigIncrements('cls_id');
            $table->unsignedBigInteger('cls_customer_id');
            $table->unsignedBigInteger('cls_token_id')->nullable();
            $table->string('cls_device', 120)->nullable();
            $table->string('cls_platform', 80)->nullable();
            $table->string('cls_browser', 80)->nullable();
            $table->string('cls_location', 120)->nullable();
            $table->string('cls_ip_address', 50)->nullable();
            $table->string('cls_user_agent', 255)->nullable();
            $table->timestamp('cls_last_active_at')->nullable();
            $table->timestamp('cls_revoked_at')->nullable();
            $table->string('cls_revoke_reason', 80)->nullable();
            $table->timestamp('cls_created_at')->useCurrent();

            $table->index(['cls_customer_id', 'cls_created_at'], 'cls_customer_created_idx');
            $table->index(['cls_customer_id', 'cls_revoked_at'], 'cls_customer_revoked_idx');
            $table->index('cls_token_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_customer_login_sessions');
    }
};

