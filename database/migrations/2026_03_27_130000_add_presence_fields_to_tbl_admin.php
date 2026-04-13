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
            if (!Schema::hasColumn('tbl_admin', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('avatar_url');
            }

            if (!Schema::hasColumn('tbl_admin', 'last_active_path')) {
                $table->string('last_active_path', 255)->nullable()->after('last_seen_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tbl_admin')) {
            return;
        }

        Schema::table('tbl_admin', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_admin', 'last_active_path')) {
                $table->dropColumn('last_active_path');
            }

            if (Schema::hasColumn('tbl_admin', 'last_seen_at')) {
                $table->dropColumn('last_seen_at');
            }
        });
    }
};
