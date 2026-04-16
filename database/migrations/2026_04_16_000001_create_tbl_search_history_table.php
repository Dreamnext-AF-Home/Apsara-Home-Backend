<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tbl_search_history', function (Blueprint $table) {
            $table->id('sh_id');
            $table->unsignedInteger('sh_customer_id')->nullable();
            $table->string('sh_query', 255);
            $table->timestamp('sh_date_created')->useCurrent();

            $table->index('sh_customer_id');
            $table->index('sh_query');
            $table->index('sh_date_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_search_history');
    }
};
