<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_expenses')) {
            return;
        }

        Schema::create('tbl_expenses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('intent', 500)->default('');
            $table->date('transaction_date');
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->timestamps();

            $table->index('category_id');
            $table->index('transaction_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_expenses');
    }
};

