<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_zqproducts')) {
            return;
        }

        Schema::table('tbl_zqproducts', function (Blueprint $table) {
            if (!Schema::hasColumn('tbl_zqproducts', 'zqp_brand_type')) {
                $table->unsignedBigInteger('zqp_brand_type')->nullable()->after('zqp_offer_id');
                $table->index('zqp_brand_type');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_zqproducts')) {
            return;
        }

        Schema::table('tbl_zqproducts', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_zqproducts', 'zqp_brand_type')) {
                $table->dropIndex(['zqp_brand_type']);
                $table->dropColumn('zqp_brand_type');
            }
        });
    }
};
