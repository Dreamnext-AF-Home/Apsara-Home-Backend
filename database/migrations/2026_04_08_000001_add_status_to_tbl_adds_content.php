<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_adds_content', function (Blueprint $table) {
            if (!Schema::hasColumn('tbl_adds_content', 'ac_status')) {
                $table->integer('ac_status')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('tbl_adds_content', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_adds_content', 'ac_status')) {
                $table->dropColumn('ac_status');
            }
        });
    }
};
