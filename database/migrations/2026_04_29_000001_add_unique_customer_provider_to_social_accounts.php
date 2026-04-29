<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_customer_social_accounts', function (Blueprint $table) {
            $table->unique(['csa_customer_id', 'csa_provider'], 'unique_customer_provider');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_customer_social_accounts', function (Blueprint $table) {
            $table->dropUnique('unique_customer_provider');
        });
    }
};
