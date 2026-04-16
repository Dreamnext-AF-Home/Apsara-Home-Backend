<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tbl_product')) {
            return;
        }

        Schema::table('tbl_product', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_product', 'pd_manual_checkout_enabled')) {
                $table->boolean('pd_manual_checkout_enabled')->default(false)->after('pd_salespromo');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tbl_product')) {
            return;
        }

        Schema::table('tbl_product', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_product', 'pd_manual_checkout_enabled')) {
                $table->dropColumn('pd_manual_checkout_enabled');
            }
        });
    }
};
