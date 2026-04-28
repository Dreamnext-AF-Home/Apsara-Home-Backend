<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Convert c_sponsor = 0 to NULL (0 is not a valid customer ID)
        DB::statement('UPDATE tbl_customer SET c_sponsor = NULL WHERE c_sponsor = 0');

        // Clean up orphaned records and add FK per table (only if table exists)
        if (Schema::hasTable('tbl_checkout_history')) {
            DB::statement('DELETE FROM tbl_checkout_history WHERE ch_customer_id NOT IN (SELECT c_userid FROM tbl_customer)');
            DB::statement('ALTER TABLE tbl_checkout_history ADD CONSTRAINT fk_checkout_customer FOREIGN KEY (ch_customer_id) REFERENCES tbl_customer(c_userid) ON DELETE CASCADE');
        }

        if (Schema::hasTable('tbl_customer_wallet_ledger')) {
            DB::statement('DELETE FROM tbl_customer_wallet_ledger WHERE wl_customer_id NOT IN (SELECT c_userid FROM tbl_customer)');
            DB::statement('ALTER TABLE tbl_customer_wallet_ledger ADD CONSTRAINT fk_wallet_ledger_customer FOREIGN KEY (wl_customer_id) REFERENCES tbl_customer(c_userid) ON DELETE CASCADE');
        }

        if (Schema::hasTable('tbl_referral_earnings')) {
            DB::statement('DELETE FROM tbl_referral_earnings WHERE re_referrer_customer_id NOT IN (SELECT c_userid FROM tbl_customer)');
            DB::statement('ALTER TABLE tbl_referral_earnings ADD CONSTRAINT fk_referral_earnings_referrer FOREIGN KEY (re_referrer_customer_id) REFERENCES tbl_customer(c_userid) ON DELETE CASCADE');
        }

        if (Schema::hasTable('tbl_group_purchase_bonus_awards')) {
            DB::statement('DELETE FROM tbl_group_purchase_bonus_awards WHERE gpba_customer_id NOT IN (SELECT c_userid FROM tbl_customer)');
            DB::statement('ALTER TABLE tbl_group_purchase_bonus_awards ADD CONSTRAINT fk_gpba_customer FOREIGN KEY (gpba_customer_id) REFERENCES tbl_customer(c_userid) ON DELETE CASCADE');
        }

        // c_sponsor → SET NULL when sponsor is deleted
        DB::statement('ALTER TABLE tbl_customer ADD CONSTRAINT fk_customer_sponsor FOREIGN KEY (c_sponsor) REFERENCES tbl_customer(c_userid) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tbl_customer DROP CONSTRAINT IF EXISTS fk_customer_sponsor');
        DB::statement('ALTER TABLE tbl_checkout_history DROP CONSTRAINT IF EXISTS fk_checkout_customer');
        DB::statement('ALTER TABLE tbl_customer_wallet_ledger DROP CONSTRAINT IF EXISTS fk_wallet_ledger_customer');
        DB::statement('ALTER TABLE tbl_referral_earnings DROP CONSTRAINT IF EXISTS fk_referral_earnings_referrer');
        DB::statement('ALTER TABLE tbl_group_purchase_bonus_awards DROP CONSTRAINT IF EXISTS fk_gpba_customer');
    }
};
