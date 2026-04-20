<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerLoginSession extends Model
{
    protected $table = 'tbl_customer_login_sessions';
    protected $primaryKey = 'cls_id';
    public $timestamps = false;

    protected $fillable = [
        'cls_customer_id',
        'cls_token_id',
        'cls_device',
        'cls_platform',
        'cls_browser',
        'cls_location',
        'cls_ip_address',
        'cls_user_agent',
        'cls_last_active_at',
        'cls_revoked_at',
        'cls_revoke_reason',
        'cls_created_at',
    ];

    protected $casts = [
        'cls_last_active_at' => 'datetime',
        'cls_revoked_at' => 'datetime',
        'cls_created_at' => 'datetime',
    ];
}

