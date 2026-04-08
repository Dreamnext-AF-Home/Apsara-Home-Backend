<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_adds_content', function (Blueprint $table) {
            $table->bigIncrements('ac_id');
            $table->string('ac_image_path')->nullable();
            $table->string('ac_video_path')->nullable();
            $table->date('ac_date_created')->nullable();
            $table->integer('ac_status')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_adds_content');
    }
};
