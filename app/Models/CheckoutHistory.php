<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckoutHistory extends Model
{
    protected $table = 'tbl_checkout_history';
    protected $primaryKey = 'ch_id';

    protected $fillable = [
        'ch_customer_id',
        'ch_checkout_id',
        'ch_payment_intent_id',
        'ch_status',
        'ch_approval_status',
        'ch_approval_notes',
        'ch_approved_by',
        'ch_approved_at',
        'ch_fulfillment_status',
        'ch_description',
        'ch_amount',
        'ch_payment_method',
        'ch_quantity',
        'ch_product_name',
        'ch_product_image',
        'ch_selected_color',
        'ch_selected_size',
        'ch_selected_type',
        'ch_customer_name',
        'ch_customer_email',
        'ch_customer_phone',
        'ch_customer_address',
        'ch_paid_at',
    ];

    protected $casts = [
        'ch_amount' => 'float',
        'ch_quantity' => 'integer',
        'ch_paid_at' => 'datetime',
        'ch_approved_at' => 'datetime',
    ];
}
