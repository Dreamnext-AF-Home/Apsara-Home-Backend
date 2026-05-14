<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckoutHistory extends Model
{
    protected $table = 'tbl_checkout_history';
    protected $primaryKey = 'ch_id';

    protected $fillable = [
        'ch_customer_id',
        'ch_referrer_customer_id',
        'ch_referral_source_type',
        'ch_checkout_id',
        'ch_payment_intent_id',
        'ch_mobile_order_id',
        'ch_status',
        'ch_approval_status',
        'ch_approval_notes',
        'ch_approved_by',
        'ch_approved_at',
        'ch_fulfillment_status',
        'ch_description',
        'ch_amount',
        'ch_shipping_fee',
        'ch_payment_method',
        'ch_quantity',
        'ch_product_name',
        'ch_product_id',
        'ch_product_sku',
        'ch_product_pv',
        'ch_earned_pv',
        'ch_commission_basis_amount',
        'ch_pv_posted_at',
        'ch_product_image',
        'ch_selected_color',
        'ch_selected_size',
        'ch_selected_type',
        'ch_customer_name',
        'ch_customer_email',
        'ch_customer_phone',
        'ch_customer_address',
        'ch_source_label',
        'ch_source_slug',
        'ch_source_host',
        'ch_source_url',
        'ch_courier',
        'ch_tracking_no',
        'ch_shipment_status',
        'ch_shipment_payload',
        'ch_refund_reason',
        'ch_refund_image_urls',
        'ch_refund_video_urls',
        'ch_refund_requested_at',
        'ch_shipped_at',
        'ch_zq_platform_order_id',
        'ch_zq_order_id',
        'ch_zq_status',
        'ch_zq_payload',
        'ch_zq_response',
        'ch_zq_synced_at',
        'ch_paid_at',
    ];

    protected $casts = [
        'ch_amount' => 'float',
        'ch_shipping_fee' => 'float',
        'ch_quantity' => 'integer',
        'ch_product_pv' => 'float',
        'ch_earned_pv' => 'float',
        'ch_commission_basis_amount' => 'float',
        'ch_paid_at' => 'datetime',
        'ch_approved_at' => 'datetime',
        'ch_pv_posted_at' => 'datetime',
        'ch_shipped_at' => 'datetime',
        'ch_shipment_payload' => 'array',
        'ch_refund_image_urls' => 'array',
        'ch_refund_video_urls' => 'array',
        'ch_refund_requested_at' => 'datetime',
        'ch_zq_payload' => 'array',
        'ch_zq_response' => 'array',
        'ch_zq_synced_at' => 'datetime',
    ];
}
