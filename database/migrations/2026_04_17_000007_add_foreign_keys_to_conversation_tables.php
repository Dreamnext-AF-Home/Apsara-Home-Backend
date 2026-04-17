<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure tbl_customer.c_userid has primary key for PostgreSQL
        if (Schema::hasTable('tbl_customer')) {
            $connection = DB::connection()->getDriverName();
            if ($connection === 'pgsql') {
                try {
                    $hasPrimaryKey = DB::select(
                        "SELECT constraint_name FROM information_schema.table_constraints
                         WHERE table_name = 'tbl_customer' AND constraint_type = 'PRIMARY KEY'"
                    );

                    if (empty($hasPrimaryKey)) {
                        DB::statement('ALTER TABLE tbl_customer ADD PRIMARY KEY (c_userid)');
                    }
                } catch (\Exception $e) {
                    // Primary key might already exist
                }
            }
        }

        // Add FK for tbl_conversations.user_id -> tbl_customer.c_userid
        if (Schema::hasTable('tbl_conversations') && Schema::hasTable('tbl_customer')) {
            Schema::table('tbl_conversations', function (Blueprint $table) {
                try {
                    $table->foreign('user_id')
                        ->references('c_userid')
                        ->on('tbl_customer')
                        ->onDelete('cascade');
                } catch (\Exception $e) {
                    // Foreign key already exists, skip
                }
            });
        }

        // Add FK for tbl_conversations.assigned_agent_id -> tbl_admin.id
        if (Schema::hasTable('tbl_conversations') && Schema::hasTable('tbl_admin')) {
            Schema::table('tbl_conversations', function (Blueprint $table) {
                try {
                    $table->foreign('assigned_agent_id')
                        ->references('id')
                        ->on('tbl_admin')
                        ->onDelete('set null');
                } catch (\Exception $e) {
                    // Foreign key already exists, skip
                }
            });
        }

        // Add FK for tbl_messages.sender_id -> tbl_customer.c_userid
        if (Schema::hasTable('tbl_messages') && Schema::hasTable('tbl_customer')) {
            Schema::table('tbl_messages', function (Blueprint $table) {
                try {
                    $table->foreign('sender_id')
                        ->references('c_userid')
                        ->on('tbl_customer')
                        ->onDelete('cascade');
                } catch (\Exception $e) {
                    // Foreign key already exists, skip
                }
            });
        }

        // Add FK for tbl_conversation_participants.user_id -> tbl_customer.c_userid
        if (Schema::hasTable('tbl_conversation_participants') && Schema::hasTable('tbl_customer')) {
            Schema::table('tbl_conversation_participants', function (Blueprint $table) {
                try {
                    $table->foreign('user_id')
                        ->references('c_userid')
                        ->on('tbl_customer')
                        ->onDelete('cascade');
                } catch (\Exception $e) {
                    // Foreign key already exists, skip
                }
            });
        }
    }

    public function down(): void
    {
        // Drop foreign keys in reverse order
        if (Schema::hasTable('tbl_conversation_participants')) {
            Schema::table('tbl_conversation_participants', function (Blueprint $table) {
                try {
                    $table->dropForeign(['user_id']);
                } catch (\Exception $e) {
                    // Foreign key doesn't exist, skip
                }
            });
        }

        if (Schema::hasTable('tbl_messages')) {
            Schema::table('tbl_messages', function (Blueprint $table) {
                try {
                    $table->dropForeign(['sender_id']);
                } catch (\Exception $e) {
                    // Foreign key doesn't exist, skip
                }
            });
        }

        if (Schema::hasTable('tbl_conversations')) {
            Schema::table('tbl_conversations', function (Blueprint $table) {
                try {
                    $table->dropForeign(['assigned_agent_id']);
                } catch (\Exception $e) {
                    // Foreign key doesn't exist, skip
                }
                try {
                    $table->dropForeign(['user_id']);
                } catch (\Exception $e) {
                    // Foreign key doesn't exist, skip
                }
            });
        }
    }
};
