<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tbl_product_reviews')) {
            return;
        }

        Schema::table('tbl_product_reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_product_reviews', 'pr_image_urls')) {
                $table->json('pr_image_urls')->nullable()->after('pr_video_url');
            }
            if (! Schema::hasColumn('tbl_product_reviews', 'pr_video_urls')) {
                $table->json('pr_video_urls')->nullable()->after('pr_image_urls');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tbl_product_reviews')) {
            return;
        }

        Schema::table('tbl_product_reviews', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_product_reviews', 'pr_video_urls')) {
                $table->dropColumn('pr_video_urls');
            }
            if (Schema::hasColumn('tbl_product_reviews', 'pr_image_urls')) {
                $table->dropColumn('pr_image_urls');
            }
        });
    }
};
