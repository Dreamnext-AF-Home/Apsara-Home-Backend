<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_order_notifications', function (Blueprint $table) {
            $table->unsignedBigInteger('on_parent_notification_id')->nullable()->after('on_id');
            $table->string('on_notification_group_id', 255)->nullable()->after('on_parent_notification_id');
            $table->string('on_event_type', 100)->nullable()->after('on_type');
            $table->string('on_priority', 20)->default('MEDIUM')->after('on_severity');
            $table->boolean('on_is_parent')->default(false)->after('on_is_read');
            $table->timestamp('on_event_date')->nullable()->after('on_read_at');

            $table->foreign('on_parent_notification_id')
                ->references('on_id')
                ->on('tbl_order_notifications')
                ->onDelete('cascade');

            $table->index('on_parent_notification_id', 'idx_on_parent_notification');
            $table->index('on_notification_group_id', 'idx_on_notification_group');
            $table->index('on_is_parent', 'idx_on_is_parent');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_order_notifications', function (Blueprint $table) {
            $table->dropForeign('tbl_order_notifications_on_parent_notification_id_foreign');
            $table->dropIndex('idx_on_parent_notification');
            $table->dropIndex('idx_on_notification_group');
            $table->dropIndex('idx_on_is_parent');
            $table->dropColumn([
                'on_parent_notification_id',
                'on_notification_group_id',
                'on_event_type',
                'on_priority',
                'on_is_parent',
                'on_event_date'
            ]);
        });
    }
};
