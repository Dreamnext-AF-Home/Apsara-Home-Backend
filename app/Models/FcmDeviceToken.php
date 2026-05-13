<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FcmDeviceToken extends Model
{
    protected $table = 'tbl_fcm_device_tokens';
    protected $primaryKey = 'fdt_id';
    public $timestamps = false;

    protected $fillable = [
        'fdt_customer_id',
        'fdt_fcm_token',
        'fdt_device_name',
        'fdt_platform',
        'fdt_is_active',
        'fdt_created_at',
        'fdt_updated_at',
    ];

    protected $casts = [
        'fdt_created_at' => 'datetime',
        'fdt_updated_at' => 'datetime',
        'fdt_is_active' => 'boolean',
    ];
}
