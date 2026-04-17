<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_conversations')) {
            return;
        }

        Schema::create('tbl_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('assigned_agent_id')->nullable();
            $table->enum('status', ['open', 'pending', 'resolved'])->default('open');
            $table->string('subject')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // Note: Foreign keys reference user tables (tbl_customer, tbl_admin, or custom user table)
            // Set up foreign keys in a separate migration if using existing user tables

            // Indexes
            $table->index('user_id');
            $table->index('assigned_agent_id');
            $table->index('status');
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_conversations');
    }
};
