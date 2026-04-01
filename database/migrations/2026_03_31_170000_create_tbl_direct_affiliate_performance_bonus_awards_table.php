<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_direct_affiliate_performance_bonus_awards', function (Blueprint $table) {
            $table->bigIncrements('dapb_id');
            $table->unsignedBigInteger('dapb_customer_id');
            $table->unsignedInteger('dapb_milestone_no');
            $table->decimal('dapb_threshold_pv', 14, 2)->default(0);
            $table->decimal('dapb_bonus_amount', 14, 2)->default(0);
            $table->unsignedInteger('dapb_direct_referrals_count')->default(0);
            $table->decimal('dapb_direct_total_pv', 14, 2)->default(0);
            $table->unsignedBigInteger('dapb_reference_order_id')->nullable();
            $table->unsignedBigInteger('dapb_awarded_by')->nullable();
            $table->timestamp('dapb_awarded_at')->nullable();
            $table->text('dapb_notes')->nullable();
            $table->timestamps();

            $table->unique(['dapb_customer_id', 'dapb_milestone_no'], 'dapb_customer_milestone_unique');
            $table->index(['dapb_customer_id', 'dapb_awarded_at'], 'dapb_customer_awarded_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_direct_affiliate_performance_bonus_awards');
    }
};
