<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tbl_web_page_content')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                WITH numbered AS (
                    SELECT
                        ctid,
                        ROW_NUMBER() OVER (ORDER BY created_at NULLS LAST, updated_at NULLS LAST, wpc_type, wpc_title) AS rn
                    FROM tbl_web_page_content
                    WHERE wpc_id IS NULL
                )
                UPDATE tbl_web_page_content t
                SET wpc_id = numbered.rn + COALESCE(
                    (SELECT MAX(wpc_id) FROM tbl_web_page_content WHERE wpc_id IS NOT NULL),
                    0
                )
                FROM numbered
                WHERE t.ctid = numbered.ctid
            SQL);

            DB::statement("CREATE SEQUENCE IF NOT EXISTS tbl_web_page_content_wpc_id_seq");
            DB::statement(<<<'SQL'
                SELECT setval(
                    'tbl_web_page_content_wpc_id_seq',
                    COALESCE((SELECT MAX(wpc_id) FROM tbl_web_page_content), 1),
                    true
                )
            SQL);
            DB::statement("ALTER TABLE tbl_web_page_content ALTER COLUMN wpc_id SET DEFAULT nextval('tbl_web_page_content_wpc_id_seq')");
            DB::statement("ALTER TABLE tbl_web_page_content ALTER COLUMN wpc_id SET NOT NULL");
            DB::statement("ALTER TABLE tbl_web_page_content DROP CONSTRAINT IF EXISTS tbl_web_page_content_pkey");
            DB::statement("ALTER TABLE tbl_web_page_content ADD CONSTRAINT tbl_web_page_content_pkey PRIMARY KEY (wpc_id)");
            return;
        }

        if ($driver === 'mysql') {
            DB::statement('UPDATE tbl_web_page_content SET wpc_id = 0 WHERE wpc_id IS NULL');
            DB::statement('SET @rownum := 0');
            DB::statement('UPDATE tbl_web_page_content SET wpc_id = (@rownum := @rownum + 1) WHERE wpc_id = 0 ORDER BY created_at, updated_at');
            DB::statement('ALTER TABLE tbl_web_page_content MODIFY wpc_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');
        }
    }

    public function down(): void
    {
        // Intentionally left empty. Reverting PK/identity on legacy tables is unsafe.
    }
};

