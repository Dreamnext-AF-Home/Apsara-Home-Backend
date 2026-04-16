<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_system_settings', function (Blueprint $table) {
            $table->string('website_qr_code_path')->nullable()->after('favicon_path');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_system_settings', function (Blueprint $table) {
            $table->dropColumn('website_qr_code_path');
        });
    }
};

