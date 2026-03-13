<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_affiliate_voucher_issuances', function (Blueprint $table) {
            $table->bigIncrements('avi_id');
            $table->unsignedBigInteger('avi_customer_id');
            $table->string('avi_code', 80)->unique();
            $table->decimal('avi_amount', 12, 2);
            $table->string('avi_status', 30)->default('active');
            $table->unsignedBigInteger('avi_redeemed_by_customer_id')->nullable();
            $table->timestamp('avi_redeemed_at')->nullable();
            $table->timestamp('avi_expires_at')->nullable();
            $table->timestamps();

            $table->index('avi_customer_id');
            $table->index('avi_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_affiliate_voucher_issuances');
    }
};
