<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OneSignalDeviceToken extends Model
{
    protected $table = 'tbl_onesignal_device_tokens';
    protected $primaryKey = 'odt_id';
    public $timestamps = false;

    protected $fillable = [
        'odt_customer_id',
        'odt_player_id',
        'odt_device_name',
        'odt_platform',
        'odt_is_active',
        'odt_created_at',
        'odt_updated_at',
    ];

    protected $casts = [
        'odt_customer_id' => 'integer',
        'odt_is_active' => 'boolean',
        'odt_created_at' => 'datetime',
        'odt_updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'odt_customer_id', 'c_userid');
    }
}
