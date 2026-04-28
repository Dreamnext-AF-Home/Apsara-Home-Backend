<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPasskey extends Model
{
    use HasFactory;

    protected $table = 'tbl_customer_passkeys';

    protected $fillable = [
        'cp_customer_id',
        'cp_credential_id',
        'cp_public_key_pem',
        'cp_name',
        'cp_transports',
        'cp_sign_count',
        'cp_last_used_at',
    ];

    protected $casts = [
        'cp_transports' => 'array',
        'cp_sign_count' => 'integer',
        'cp_last_used_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'cp_customer_id', 'c_userid');
    }
}

