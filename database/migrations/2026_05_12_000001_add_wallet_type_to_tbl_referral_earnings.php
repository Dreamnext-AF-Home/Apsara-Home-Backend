<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_referral_earnings')) {
            return;
        }

        if (Schema::hasColumn('tbl_referral_earnings', 're_wallet_type')) {
            return;
        }

        // Drop old unique safely — name may differ or not exist in production
        DB::statement('ALTER TABLE tbl_referral_earnings DROP CONSTRAINT IF EXISTS re_order_referrer_unique');
        DB::statement('ALTER TABLE tbl_referral_earnings DROP CONSTRAINT IF EXISTS tbl_referral_earnings_re_order_id_re_referrer_customer_id_unique');

        Schema::table('tbl_referral_earnings', function (Blueprint $table) {
            // Cash or egc — existing rows default to cash (no behavior change)
            $table->string('re_wallet_type', 20)->default('cash')->after('re_status');

            // New unique includes wallet type so one order can have one cash + one egc record
            $table->unique(
                ['re_order_id', 're_referrer_customer_id', 're_wallet_type'],
                're_order_referrer_wallet_unique'
            );
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_referral_earnings')) {
            return;
        }

        DB::statement('ALTER TABLE tbl_referral_earnings DROP CONSTRAINT IF EXISTS re_order_referrer_wallet_unique');

        Schema::table('tbl_referral_earnings', function (Blueprint $table) {
            $table->dropColumn('re_wallet_type');
        });
    }
};
