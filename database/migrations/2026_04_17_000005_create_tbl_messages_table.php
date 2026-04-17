<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tbl_messages')) {
            return;
        }

        Schema::create('tbl_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('sender_id');
            $table->longText('message');
            $table->boolean('is_internal')->default(false);
            $table->string('attachment_url')->nullable();
            $table->string('attachment_filename')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('conversation_id')
                ->references('id')
                ->on('tbl_conversations')
                ->onDelete('cascade');

            // Note: sender_id references user tables (tbl_customer, tbl_admin, or custom user table)
            // Set up FK for sender_id in a separate migration if using existing user tables

            // Indexes
            $table->index('conversation_id');
            $table->index('sender_id');
            $table->index('is_internal');
            $table->index(['conversation_id', 'created_at']);
            $table->index('read_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tbl_messages');
    }
};
