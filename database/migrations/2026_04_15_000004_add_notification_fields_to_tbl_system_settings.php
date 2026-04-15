<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_system_settings', function (Blueprint $table) {
            $table->boolean('email_notifications')->nullable()->after('enable_2fa');
            $table->boolean('sms_notifications')->nullable()->after('email_notifications');
            $table->boolean('admin_alerts')->nullable()->after('sms_notifications');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_system_settings', function (Blueprint $table) {
            $table->dropColumn([
                'email_notifications',
                'sms_notifications',
                'admin_alerts',
            ]);
        });
    }
};
