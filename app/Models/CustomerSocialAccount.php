<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerSocialAccount extends Model
{
    use HasFactory;

    protected $table = 'tbl_customer_social_accounts';

    protected $fillable = [
        'csa_customer_id',
        'csa_provider',
        'csa_provider_id',
        'csa_token',
        'csa_refresh_token',
        'csa_token_expires_at',
        'csa_provider_data',
    ];

    protected $casts = [
        'csa_provider_data' => 'array',
        'csa_token_expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'csa_customer_id', 'c_userid');
    }
}
