<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_system_settings', function (Blueprint $table) {
            $table->text('branches')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_system_settings', function (Blueprint $table) {
            $table->dropColumn('branches');
        });
    }
};
