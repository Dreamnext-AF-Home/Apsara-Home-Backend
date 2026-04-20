<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_checkout_history')) {
            return;
        }

        Schema::table('tbl_checkout_history', function (Blueprint $table) {
            if (!Schema::hasColumn('tbl_checkout_history', 'ch_source_label')) {
                $table->string('ch_source_label', 255)->nullable()->after('ch_customer_address');
            }
            if (!Schema::hasColumn('tbl_checkout_history', 'ch_source_slug')) {
                $table->string('ch_source_slug', 255)->nullable()->after('ch_source_label');
            }
            if (!Schema::hasColumn('tbl_checkout_history', 'ch_source_host')) {
                $table->string('ch_source_host', 255)->nullable()->after('ch_source_slug');
            }
            if (!Schema::hasColumn('tbl_checkout_history', 'ch_source_url')) {
                $table->text('ch_source_url')->nullable()->after('ch_source_host');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('tbl_checkout_history')) {
            return;
        }

        Schema::table('tbl_checkout_history', function (Blueprint $table) {
            foreach ([
                'ch_source_url',
                'ch_source_host',
                'ch_source_slug',
                'ch_source_label',
            ] as $column) {
                if (Schema::hasColumn('tbl_checkout_history', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
