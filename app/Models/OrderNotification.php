<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderNotification extends Model
{
    protected $table = 'tbl_order_notifications';
    protected $primaryKey = 'on_id';
    public $timestamps = false;

    protected $fillable = [
        'on_customer_id',
        'on_checkout_id',
        'on_mobile_order_id',
        'on_type',
        'on_severity',
        'on_title',
        'on_message',
        'on_product_name',
        'on_product_image',
        'on_product_sku',
        'on_quantity',
        'on_amount',
        'on_status',
        'on_payment_method',
        'on_href',
        'on_payload',
        'on_is_read',
        'on_read_at',
        'on_created_at',
    ];

    protected $casts = [
        'on_customer_id' => 'integer',
        'on_quantity' => 'integer',
        'on_amount' => 'decimal:2',
        'on_payload' => 'array',
        'on_is_read' => 'boolean',
        'on_read_at' => 'datetime',
        'on_created_at' => 'datetime',
    ];

    public function markAsRead(): void
    {
        $this->update([
            'on_is_read' => true,
            'on_read_at' => now(),
        ]);
    }
}
