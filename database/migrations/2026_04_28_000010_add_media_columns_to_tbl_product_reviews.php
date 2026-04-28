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
            if (! Schema::hasColumn('tbl_product_reviews', 'pr_image_url')) {
                $table->string('pr_image_url', 1200)->nullable()->after('pr_review');
            }
            if (! Schema::hasColumn('tbl_product_reviews', 'pr_video_url')) {
                $table->string('pr_video_url', 1200)->nullable()->after('pr_image_url');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tbl_product_reviews')) {
            return;
        }

        Schema::table('tbl_product_reviews', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_product_reviews', 'pr_video_url')) {
                $table->dropColumn('pr_video_url');
            }
            if (Schema::hasColumn('tbl_product_reviews', 'pr_image_url')) {
                $table->dropColumn('pr_image_url');
            }
        });
    }
};
