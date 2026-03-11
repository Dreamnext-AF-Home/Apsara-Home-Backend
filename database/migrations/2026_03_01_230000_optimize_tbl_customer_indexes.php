<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_tbl_customer_lock_accnt ON tbl_customer (c_lockstatus, c_accnt_status)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_tbl_customer_rank ON tbl_customer (c_rank)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_tbl_customer_sponsor ON tbl_customer (c_sponsor)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_tbl_customer_userid_desc ON tbl_customer (c_userid DESC)');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_tbl_customer_username_trgm ON tbl_customer USING gin (c_username gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_tbl_customer_email_trgm ON tbl_customer USING gin (c_email gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_tbl_customer_fname_trgm ON tbl_customer USING gin (c_fname gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_tbl_customer_mname_trgm ON tbl_customer USING gin (c_mname gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_tbl_customer_lname_trgm ON tbl_customer USING gin (c_lname gin_trgm_ops)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_tbl_customer_lname_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_tbl_customer_mname_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_tbl_customer_fname_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_tbl_customer_email_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_tbl_customer_username_trgm');

        DB::statement('DROP INDEX IF EXISTS idx_tbl_customer_userid_desc');
        DB::statement('DROP INDEX IF EXISTS idx_tbl_customer_sponsor');
        DB::statement('DROP INDEX IF EXISTS idx_tbl_customer_rank');
        DB::statement('DROP INDEX IF EXISTS idx_tbl_customer_lock_accnt');
    }
};
