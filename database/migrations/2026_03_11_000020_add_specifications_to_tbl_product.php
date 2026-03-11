<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_product', function (Blueprint $table) {
            $table->text('pd_specifications')->nullable()->default(null)->after('pd_description');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_product', function (Blueprint $table) {
            $table->dropColumn('pd_specifications');
        });
    }
};
