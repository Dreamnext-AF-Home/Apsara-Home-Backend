<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tbl_expenses')) {
            return;
        }

        if (Schema::hasColumn('tbl_expenses', 'id')) {
            return;
        }

        // Use raw SQL for Postgres compatibility and to safely backfill existing rows.
        DB::statement('ALTER TABLE "tbl_expenses" ADD COLUMN "id" BIGSERIAL');

        // Ensure existing rows have ids.
        DB::statement('UPDATE "tbl_expenses" SET "id" = nextval(pg_get_serial_sequence(\'tbl_expenses\', \'id\')) WHERE "id" IS NULL');

        // Make it unique so edit/delete can rely on it even if the table already had a different primary key.
        Schema::table('tbl_expenses', function (Blueprint $table) {
            $table->unique('id', 'tbl_expenses_id_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tbl_expenses')) {
            return;
        }

        if (! Schema::hasColumn('tbl_expenses', 'id')) {
            return;
        }

        Schema::table('tbl_expenses', function (Blueprint $table) {
            $table->dropUnique('tbl_expenses_id_unique');
        });

        DB::statement('ALTER TABLE "tbl_expenses" DROP COLUMN "id"');
    }
};

