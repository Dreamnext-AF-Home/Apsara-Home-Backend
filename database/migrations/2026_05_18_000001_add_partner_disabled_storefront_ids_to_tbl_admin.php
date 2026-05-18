<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tbl_admin')) {
            return;
        }

        Schema::table('tbl_admin', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_admin', 'partner_disabled_storefront_ids')) {
                $table->json('partner_disabled_storefront_ids')->nullable()->after('admin_permissions');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tbl_admin')) {
            return;
        }

        Schema::table('tbl_admin', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_admin', 'partner_disabled_storefront_ids')) {
                $table->dropColumn('partner_disabled_storefront_ids');
            }
        });
    }
};

