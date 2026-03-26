<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_admin', function (Blueprint $table) {
            $table->boolean('is_banned')->default(false)->after('avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_admin', function (Blueprint $table) {
            $table->dropColumn('is_banned');
        });
    }
};
