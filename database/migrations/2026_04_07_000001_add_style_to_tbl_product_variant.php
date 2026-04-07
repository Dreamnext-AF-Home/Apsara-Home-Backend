<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_product_variant')) {
            return;
        }

        Schema::table('tbl_product_variant', function (Blueprint $table) {
            if (!Schema::hasColumn('tbl_product_variant', 'pv_style')) {
                $table->string('pv_style', 80)->nullable()->after('pv_size');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_product_variant')) {
            return;
        }

        Schema::table('tbl_product_variant', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_product_variant', 'pv_style')) {
                $table->dropColumn('pv_style');
            }
        });
    }
};
