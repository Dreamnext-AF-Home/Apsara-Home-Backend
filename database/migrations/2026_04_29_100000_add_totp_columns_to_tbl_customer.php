<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_customer', function (Blueprint $table) {
            $table->text('c_totp_secret')->nullable()->after('c_two_factor_enabled');
            $table->boolean('c_totp_enabled')->default(false)->after('c_totp_secret');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_customer', function (Blueprint $table) {
            $table->dropColumn(['c_totp_secret', 'c_totp_enabled']);
        });
    }
};
