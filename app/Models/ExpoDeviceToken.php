<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpoDeviceToken extends Model
{
    protected $table = 'tbl_expo_device_tokens';
    protected $primaryKey = 'edt_id';
    public $timestamps = false;

    protected $fillable = [
        'edt_customer_id',
        'edt_token',
        'edt_device_name',
        'edt_platform',
        'edt_is_active',
        'edt_created_at',
        'edt_updated_at',
    ];

    protected $casts = [
        'edt_customer_id' => 'integer',
        'edt_is_active' => 'boolean',
        'edt_created_at' => 'datetime',
        'edt_updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'edt_customer_id', 'c_userid');
    }
}
