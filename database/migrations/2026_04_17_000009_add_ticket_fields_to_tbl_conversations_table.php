<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tbl_conversations')) {
            return;
        }

        Schema::table('tbl_conversations', function (Blueprint $table) {
            // Add ticket-related fields
            $table->string('ticket_number', 50)->nullable()->unique()->after('subject');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium')->after('status');
            $table->enum('category', ['billing', 'technical', 'shipping', 'product', 'account', 'other'])->nullable()->after('priority');
            $table->string('tags', 255)->nullable()->after('category'); // Comma-separated tags
            $table->decimal('resolution_time_minutes', 8, 2)->nullable()->after('resolved_at');
            $table->text('resolution_notes')->nullable()->after('resolution_time_minutes');
            $table->integer('view_count')->default(0)->after('resolution_notes');

            // Indexes for new fields
            $table->index('ticket_number');
            $table->index('priority');
            $table->index('category');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('tbl_conversations')) {
            Schema::table('tbl_conversations', function (Blueprint $table) {
                $table->dropIndex(['ticket_number']);
                $table->dropIndex(['priority']);
                $table->dropIndex(['category']);
                $table->dropColumn([
                    'ticket_number',
                    'priority',
                    'category',
                    'tags',
                    'resolution_time_minutes',
                    'resolution_notes',
                    'view_count'
                ]);
            });
        }
    }
};
