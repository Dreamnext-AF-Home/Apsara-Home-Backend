<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_conversation_participants')) {
            return;
        }

        Schema::create('tbl_conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('conversation_id')
                ->references('id')
                ->on('tbl_conversations')
                ->onDelete('cascade');

            // Note: user_id references user tables (tbl_customer, tbl_admin, or custom user table)
            // Set up FK for user_id in a separate migration if using existing user tables

            // Unique constraint to prevent duplicate participants
            $table->unique(['conversation_id', 'user_id']);

            // Indexes
            $table->index('conversation_id');
            $table->index('user_id');
            $table->index(['user_id', 'joined_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_conversation_participants');
    }
};
