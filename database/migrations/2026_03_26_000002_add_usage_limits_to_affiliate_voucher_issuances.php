<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_affiliate_voucher_issuances')) {
            return;
        }

        Schema::table('tbl_affiliate_voucher_issuances', function (Blueprint $table) {
            if (!Schema::hasColumn('tbl_affiliate_voucher_issuances', 'avi_max_uses')) {
                $table->unsignedInteger('avi_max_uses')->nullable()->after('avi_expires_at');
            }
            if (!Schema::hasColumn('tbl_affiliate_voucher_issuances', 'avi_used_count')) {
                $table->unsignedInteger('avi_used_count')->default(0)->after('avi_max_uses');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_affiliate_voucher_issuances')) {
            return;
        }

        Schema::table('tbl_affiliate_voucher_issuances', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_affiliate_voucher_issuances', 'avi_used_count')) {
                $table->dropColumn('avi_used_count');
            }
            if (Schema::hasColumn('tbl_affiliate_voucher_issuances', 'avi_max_uses')) {
                $table->dropColumn('avi_max_uses');
            }
        });
    }
};
