<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tbl_expenses')) {
            return;
        }

        Schema::table('tbl_expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_expenses', 'sub_category_name')) {
                $table->string('sub_category_name', 180)->default('')->after('category_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tbl_expenses')) {
            return;
        }

        Schema::table('tbl_expenses', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_expenses', 'sub_category_name')) {
                $table->dropColumn('sub_category_name');
            }
        });
    }
};
