<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_customer')) {
            return;
        }

        if (!Schema::hasColumn('tbl_customer', 'c_partner_slug')) {
            Schema::table('tbl_customer', function (Blueprint $table) {
                $table->string('c_partner_slug', 255)->nullable()->after('c_zipcode');
                $table->index('c_partner_slug', 'idx_tbl_customer_partner_slug');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_customer')) {
            return;
        }

        if (Schema::hasColumn('tbl_customer', 'c_partner_slug')) {
            Schema::table('tbl_customer', function (Blueprint $table) {
                $table->dropIndex('idx_tbl_customer_partner_slug');
                $table->dropColumn('c_partner_slug');
            });
        }
    }
};

