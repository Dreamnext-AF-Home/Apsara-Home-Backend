<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_customer_passkeys')) {
            return;
        }

        Schema::create('tbl_customer_passkeys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cp_customer_id');
            $table->string('cp_credential_id', 255)->unique();
            $table->longText('cp_public_key_pem');
            $table->string('cp_name', 120)->nullable();
            $table->json('cp_transports')->nullable();
            $table->unsignedBigInteger('cp_sign_count')->default(0);
            $table->timestamp('cp_last_used_at')->nullable();
            $table->timestamps();

            $table->index('cp_customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_customer_passkeys');
    }
};

