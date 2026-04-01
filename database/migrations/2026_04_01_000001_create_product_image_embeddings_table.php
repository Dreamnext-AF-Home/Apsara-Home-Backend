<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_product_image_embeddings')) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
            DB::statement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS tbl_product_image_embeddings (
                    pie_id BIGSERIAL PRIMARY KEY,
                    pie_product_id BIGINT NOT NULL,
                    pie_photo_id BIGINT NULL,
                    pie_image_url TEXT NOT NULL,
                    pie_embedding VECTOR(512) NOT NULL,
                    created_at TIMESTAMP NULL,
                    updated_at TIMESTAMP NULL
                )
            SQL);

            DB::statement('CREATE INDEX IF NOT EXISTS idx_pie_product_id ON tbl_product_image_embeddings(pie_product_id)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_pie_photo_id ON tbl_product_image_embeddings(pie_photo_id)');
            DB::statement("CREATE INDEX IF NOT EXISTS idx_pie_embedding ON tbl_product_image_embeddings USING ivfflat (pie_embedding vector_cosine_ops) WITH (lists = 100)");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_product_image_embeddings')) {
            Schema::drop('tbl_product_image_embeddings');
        }
    }
};
