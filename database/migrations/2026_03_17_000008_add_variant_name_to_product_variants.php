<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_product_variant') && !Schema::hasColumn('tbl_product_variant', 'pv_name')) {
            Schema::table('tbl_product_variant', function (Blueprint $table) {
                $table->string('pv_name', 120)->nullable()->after('pv_sku');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_product_variant') && Schema::hasColumn('tbl_product_variant', 'pv_name')) {
            Schema::table('tbl_product_variant', function (Blueprint $table) {
                $table->dropColumn('pv_name');
            });
        }
    }
};
