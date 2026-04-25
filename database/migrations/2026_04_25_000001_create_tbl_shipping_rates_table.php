<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_shipping_rates')) {
            Schema::create('tbl_shipping_rates', function (Blueprint $table) {
                $table->bigIncrements('sr_id');
                $table->string('sr_province', 120);
                $table->string('sr_city', 160);
                $table->string('sr_province_key', 120);
                $table->string('sr_city_key', 160);
                $table->decimal('sr_fee', 12, 2)->default(0);
                $table->boolean('sr_status')->default(true);
                $table->timestamps();

                $table->unique(['sr_province_key', 'sr_city_key'], 'shipping_rate_location_unique');
                $table->index(['sr_status', 'sr_province_key'], 'shipping_rate_status_province_idx');
            });
        }

        if (Schema::hasTable('tbl_checkout_history') && !Schema::hasColumn('tbl_checkout_history', 'ch_shipping_fee')) {
            Schema::table('tbl_checkout_history', function (Blueprint $table) {
                $table->decimal('ch_shipping_fee', 12, 2)->default(0)->after('ch_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_checkout_history') && Schema::hasColumn('tbl_checkout_history', 'ch_shipping_fee')) {
            Schema::table('tbl_checkout_history', function (Blueprint $table) {
                $table->dropColumn('ch_shipping_fee');
            });
        }

        Schema::dropIfExists('tbl_shipping_rates');
    }
};
