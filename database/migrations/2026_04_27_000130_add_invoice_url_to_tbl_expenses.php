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
            if (! Schema::hasColumn('tbl_expenses', 'invoice_url')) {
                $table->string('invoice_url', 2000)->nullable()->after('sub_category_name');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tbl_expenses')) {
            return;
        }

        Schema::table('tbl_expenses', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_expenses', 'invoice_url')) {
                $table->dropColumn('invoice_url');
            }
        });
    }
};
