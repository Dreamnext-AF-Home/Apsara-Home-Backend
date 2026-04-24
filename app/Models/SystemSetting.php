<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $table = 'tbl_system_settings';

    protected $fillable = [
        'system_name',
        'company_name',
        'support_email',
        'contact_number',
        'address',
        'branches',
        'logo_path',
        'favicon_path',
        'website_qr_code_path',
        'timezone',
        'currency',
        'date_format',
        'language',
        'session_timeout_minutes',
        'max_login_attempts',
        'password_min_length',
        'enable_2fa',
        'email_notifications',
        'sms_notifications',
        'admin_alerts',
        'enable_test_payments',
        'enable_manual_checkout_mode',
        'zq_saved_cursor',
        'zq_last_synced_at',
    ];

    protected $casts = [
        'enable_2fa' => 'boolean',
        'email_notifications' => 'boolean',
        'sms_notifications' => 'boolean',
        'admin_alerts' => 'boolean',
        'enable_test_payments' => 'boolean',
        'enable_manual_checkout_mode' => 'boolean',
        'zq_last_synced_at' => 'datetime',
    ];
}
