<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_system_settings', function (Blueprint $table) {
            $table->unsignedInteger('session_timeout_minutes')->nullable()->after('language');
            $table->unsignedInteger('max_login_attempts')->nullable()->after('session_timeout_minutes');
            $table->unsignedInteger('password_min_length')->nullable()->after('max_login_attempts');
            $table->boolean('enable_2fa')->nullable()->after('password_min_length');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_system_settings', function (Blueprint $table) {
            $table->dropColumn([
                'session_timeout_minutes',
                'max_login_attempts',
                'password_min_length',
                'enable_2fa',
            ]);
        });
    }
};
