<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_customer_social_accounts', function (Blueprint $table) {
            $table->text('csa_token')->nullable()->change();
            $table->text('csa_refresh_token')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tbl_customer_social_accounts', function (Blueprint $table) {
            $table->string('csa_token')->nullable()->change();
            $table->string('csa_refresh_token')->nullable()->change();
        });
    }
};
