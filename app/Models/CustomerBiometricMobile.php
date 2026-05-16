<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerBiometricMobile extends Model
{
    protected $table = 'tbl_customer_biometrics_mobile';

    protected $fillable = [
        'cbm_customer_id',
        'cbm_device_id',
        'cbm_device_name',
        'cbm_device_type',
        'cbm_credential_token',
        'cbm_public_key',
        'cbm_last_used_at',
        'cbm_is_active',
    ];

    protected $casts = [
        'cbm_last_used_at' => 'datetime',
        'cbm_is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'cbm_customer_id', 'c_userid');
    }
}
