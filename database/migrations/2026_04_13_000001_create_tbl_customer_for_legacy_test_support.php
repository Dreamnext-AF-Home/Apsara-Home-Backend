<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_customer')) {
            return;
        }

        Schema::create('tbl_customer', function (Blueprint $table) {
            $table->increments('c_userid');
            $table->string('c_fname', 255)->nullable();
            $table->string('c_mname', 255)->nullable();
            $table->string('c_lname', 255)->nullable();
            $table->string('c_username', 255)->unique();
            $table->string('c_email', 255)->unique();
            $table->text('c_password')->nullable();
            $table->text('c_password_pin')->nullable();
            $table->boolean('c_password_change_required')->default(false);
            $table->string('c_mobile', 20)->nullable();
            $table->date('c_bdate')->nullable();
            $table->unsignedTinyInteger('c_gender')->default(0);
            $table->string('c_occupation', 155)->nullable();
            $table->string('c_country', 45)->nullable();
            $table->unsignedInteger('c_sponsor')->default(0);
            $table->unsignedTinyInteger('c_rank')->default(0);
            $table->unsignedTinyInteger('c_accnt_status')->default(0);
            $table->unsignedTinyInteger('c_lockstatus')->default(0);
            $table->decimal('c_totalpair', 12, 2)->default(0);
            $table->decimal('c_gpv', 12, 2)->default(0);
            $table->decimal('c_totalincome', 12, 2)->default(0);
            $table->timestamp('c_date_started')->nullable();
            $table->timestamp('c_last_logindate')->nullable();
            $table->string('c_avatar_url', 2048)->nullable();
            $table->string('c_address', 500)->nullable();
            $table->string('c_barangay', 255)->nullable();
            $table->string('c_city', 255)->nullable();
            $table->string('c_province', 255)->nullable();
            $table->string('c_region', 255)->nullable();
            $table->string('c_zipcode', 20)->nullable();
            $table->string('c_region_code', 30)->nullable();
            $table->string('c_province_code', 30)->nullable();
            $table->string('c_city_code', 30)->nullable();
            $table->string('c_barangay_code', 30)->nullable();

            $table->index(['c_lockstatus', 'c_accnt_status'], 'idx_tbl_customer_status_combo');
            $table->index('c_rank', 'idx_tbl_customer_rank');
            $table->index('c_sponsor', 'idx_tbl_customer_sponsor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_customer');
    }
};
