<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_member_activity_logs')) {
            return;
        }

        Schema::create('tbl_member_activity_logs', function (Blueprint $table) {
            $table->bigIncrements('mal_id');
            $table->unsignedBigInteger('mal_customer_id');
            $table->string('mal_activity_type', 50); // login, purchase, profile_update, wallet_transaction, encashment_request, verification_request, etc.
            $table->string('mal_action', 50); // create, update, delete, approve, reject, etc.
            $table->string('mal_description', 255)->nullable();
            $table->string('mal_resource_type', 50)->nullable(); // order, encashment_request, verification_request, profile, etc.
            $table->unsignedBigInteger('mal_resource_id')->nullable(); // ID of the resource being acted upon
            $table->json('mal_details')->nullable(); // Additional data (amounts, changes, etc.)
            $table->json('mal_metadata')->nullable(); // IP address, user agent, etc.
            $table->string('mal_ip_address', 50)->nullable();
            $table->string('mal_user_agent', 255)->nullable();
            $table->timestamp('mal_created_at')->useCurrent();

            // Indexes for efficient querying
            $table->index(['mal_customer_id', 'mal_created_at'], 'mal_customer_date_idx');
            $table->index(['mal_activity_type', 'mal_created_at'], 'mal_activity_date_idx');
            $table->index(['mal_resource_type', 'mal_resource_id'], 'mal_resource_idx');
            $table->index('mal_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_member_activity_logs');
    }
};
