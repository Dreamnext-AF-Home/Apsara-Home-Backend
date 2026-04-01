<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_checkout_history')) {
            return;
        }

        Schema::table('tbl_checkout_history', function (Blueprint $table) {
            if (!Schema::hasColumn('tbl_checkout_history', 'ch_referrer_customer_id')) {
                $table->unsignedBigInteger('ch_referrer_customer_id')->nullable()->after('ch_customer_id');
            }
            if (!Schema::hasColumn('tbl_checkout_history', 'ch_referral_source_type')) {
                $table->string('ch_referral_source_type', 50)->nullable()->after('ch_referrer_customer_id');
            }
            if (!Schema::hasColumn('tbl_checkout_history', 'ch_commission_basis_amount')) {
                $table->decimal('ch_commission_basis_amount', 12, 2)->default(0)->after('ch_earned_pv');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_checkout_history')) {
            return;
        }

        Schema::table('tbl_checkout_history', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_checkout_history', 'ch_commission_basis_amount')) {
                $table->dropColumn('ch_commission_basis_amount');
            }
            if (Schema::hasColumn('tbl_checkout_history', 'ch_referral_source_type')) {
                $table->dropColumn('ch_referral_source_type');
            }
            if (Schema::hasColumn('tbl_checkout_history', 'ch_referrer_customer_id')) {
                $table->dropColumn('ch_referrer_customer_id');
            }
        });
    }
};
