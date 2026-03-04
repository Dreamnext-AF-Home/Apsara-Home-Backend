<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_encashment_payout_methods')) {
            return;
        }

        Schema::create('tbl_encashment_payout_methods', function (Blueprint $table) {
            $table->bigIncrements('epm_id');
            $table->unsignedBigInteger('epm_customer_id');
            $table->string('epm_label', 120);
            $table->string('epm_method_type', 30)->default('gcash');
            $table->string('epm_channel', 20)->default('gcash');
            $table->string('epm_account_name', 255)->nullable();
            $table->string('epm_account_number', 120)->nullable();
            $table->string('epm_mobile_number', 40)->nullable();
            $table->string('epm_email', 255)->nullable();
            $table->string('epm_bank_name', 120)->nullable();
            $table->string('epm_bank_code', 50)->nullable();
            $table->string('epm_account_type', 20)->nullable();
            $table->string('epm_card_holder_name', 255)->nullable();
            $table->string('epm_card_brand', 20)->nullable();
            $table->string('epm_card_last4', 4)->nullable();
            $table->boolean('epm_is_default')->default(false);
            $table->timestamps();

            $table->index(['epm_customer_id', 'created_at'], 'epm_customer_created_idx');
            $table->index(['epm_customer_id', 'epm_is_default'], 'epm_customer_default_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_encashment_payout_methods');
    }
};
