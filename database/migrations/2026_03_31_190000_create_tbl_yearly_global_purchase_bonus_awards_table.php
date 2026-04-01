<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_yearly_global_purchase_bonus_awards', function (Blueprint $table) {
            $table->bigIncrements('ygpba_id');
            $table->unsignedBigInteger('ygpba_customer_id');
            $table->unsignedInteger('ygpba_bonus_year');
            $table->unsignedInteger('ygpba_rank_no');
            $table->decimal('ygpba_yearly_pv', 14, 2)->default(0);
            $table->decimal('ygpba_bonus_rate', 10, 6)->default(0);
            $table->decimal('ygpba_bonus_amount', 14, 2)->default(0);
            $table->unsignedBigInteger('ygpba_awarded_by')->nullable();
            $table->timestamp('ygpba_awarded_at')->nullable();
            $table->text('ygpba_notes')->nullable();
            $table->timestamps();

            $table->unique(['ygpba_bonus_year', 'ygpba_customer_id'], 'ygpba_year_customer_unique');
            $table->unique(['ygpba_bonus_year', 'ygpba_rank_no'], 'ygpba_year_rank_unique');
            $table->index(['ygpba_bonus_year', 'ygpba_rank_no'], 'ygpba_year_rank_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_yearly_global_purchase_bonus_awards');
    }
};
