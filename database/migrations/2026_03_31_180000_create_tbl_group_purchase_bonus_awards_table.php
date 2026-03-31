<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_group_purchase_bonus_awards', function (Blueprint $table) {
            $table->bigIncrements('gpba_id');
            $table->unsignedBigInteger('gpba_customer_id');
            $table->unsignedBigInteger('gpba_source_customer_id')->nullable();
            $table->unsignedInteger('gpba_level_no');
            $table->unsignedBigInteger('gpba_reference_order_id')->nullable();
            $table->string('gpba_checkout_id', 120)->nullable();
            $table->decimal('gpba_earned_pv', 14, 2)->default(0);
            $table->decimal('gpba_bonus_rate', 10, 6)->default(0);
            $table->decimal('gpba_bonus_amount', 14, 2)->default(0);
            $table->unsignedInteger('gpba_unlocked_max_level')->default(0);
            $table->unsignedBigInteger('gpba_awarded_by')->nullable();
            $table->timestamp('gpba_awarded_at')->nullable();
            $table->text('gpba_notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['gpba_customer_id', 'gpba_reference_order_id', 'gpba_level_no'],
                'gpba_customer_order_level_unique'
            );
            $table->index(['gpba_customer_id', 'gpba_level_no'], 'gpba_customer_level_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_group_purchase_bonus_awards');
    }
};
