<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tbl_expenses')) {
            return;
        }

        Schema::table('tbl_expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('tbl_expenses', 'category_id')) {
                $table->unsignedBigInteger('category_id')->nullable()->index();
            }

            if (! Schema::hasColumn('tbl_expenses', 'amount')) {
                $table->decimal('amount', 12, 2)->default(0);
            }

            if (! Schema::hasColumn('tbl_expenses', 'intent')) {
                $table->string('intent', 500)->default('');
            }

            if (! Schema::hasColumn('tbl_expenses', 'transaction_date')) {
                $table->date('transaction_date')->nullable()->index();
            }

            if (! Schema::hasColumn('tbl_expenses', 'status')) {
                $table->unsignedTinyInteger('status')->default(1)->index();
            }

            if (! Schema::hasColumn('tbl_expenses', 'created_by_admin_id')) {
                $table->unsignedBigInteger('created_by_admin_id')->nullable()->index();
            }

            // Some existing schemas may not have timestamps.
            if (! Schema::hasColumn('tbl_expenses', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn('tbl_expenses', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tbl_expenses')) {
            return;
        }

        Schema::table('tbl_expenses', function (Blueprint $table) {
            if (Schema::hasColumn('tbl_expenses', 'category_id')) {
                $table->dropColumn('category_id');
            }
            if (Schema::hasColumn('tbl_expenses', 'amount')) {
                $table->dropColumn('amount');
            }
            if (Schema::hasColumn('tbl_expenses', 'intent')) {
                $table->dropColumn('intent');
            }
            if (Schema::hasColumn('tbl_expenses', 'transaction_date')) {
                $table->dropColumn('transaction_date');
            }
            if (Schema::hasColumn('tbl_expenses', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('tbl_expenses', 'created_by_admin_id')) {
                $table->dropColumn('created_by_admin_id');
            }
            if (Schema::hasColumn('tbl_expenses', 'created_at')) {
                $table->dropColumn('created_at');
            }
            if (Schema::hasColumn('tbl_expenses', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }
};

