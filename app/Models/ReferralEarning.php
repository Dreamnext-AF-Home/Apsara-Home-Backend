<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralEarning extends Model
{
    protected $table = 'tbl_referral_earnings';
    protected $primaryKey = 're_id';

    protected $fillable = [
        're_order_id',
        're_checkout_id',
        're_buyer_customer_id',
        're_referrer_customer_id',
        're_product_id',
        're_product_sku',
        're_quantity',
        're_order_amount',
        're_commission_basis_amount',
        're_amount',
        're_status',
        're_source_type',
        're_reference_no',
        're_notes',
        're_available_at',
        're_released_by',
        're_released_at',
        're_cancelled_by',
        're_cancelled_at',
    ];

    protected $casts = [
        're_quantity' => 'integer',
        're_order_amount' => 'float',
        're_commission_basis_amount' => 'float',
        're_amount' => 'float',
        're_available_at' => 'datetime',
        're_released_at' => 'datetime',
        're_cancelled_at' => 'datetime',
    ];
}
