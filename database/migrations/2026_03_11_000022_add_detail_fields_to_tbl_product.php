<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_product', function (Blueprint $table) {
            if (!Schema::hasColumn('tbl_product', 'pd_material')) {
                $table->text('pd_material')->nullable()->after('pd_specifications');
            }
            if (!Schema::hasColumn('tbl_product', 'pd_pswidth')) {
                $table->float('pd_pswidth')->default(0)->after('pd_pslenght');
            }
            if (!Schema::hasColumn('tbl_product', 'pd_assembly_required')) {
                $table->smallInteger('pd_assembly_required')->default(0)->after('pd_psheight');
            }
            if (!Schema::hasColumn('tbl_product', 'pd_warranty')) {
                $table->string('pd_warranty', 255)->nullable()->after('pd_assembly_required');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tbl_product', function (Blueprint $table) {
            $table->dropColumn(array_filter(
                ['pd_material', 'pd_pswidth', 'pd_assembly_required', 'pd_warranty'],
                fn ($col) => Schema::hasColumn('tbl_product', $col)
            ));
        });
    }
};
