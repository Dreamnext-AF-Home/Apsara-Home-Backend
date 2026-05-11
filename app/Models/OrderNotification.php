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

    public static function updateStatusForCheckout(string $checkoutId, string $status): void
    {
        $hrefPrefix = match ($status) {
            'pending' => 'purchases://pending',
            'paid', 'succeeded', 'success' => 'purchases://paid',
            'processing' => 'purchases://processing',
            'shipped' => 'purchases://shipped',
            'to_receive', 'out_for_delivery' => 'purchases://to_receive',
            'delivered' => 'purchases://delivered',
            default => 'purchases://pending',
        };

        $severity = match ($status) {
            'paid', 'succeeded', 'success' => 'success',
            'delivered' => 'success',
            'shipped', 'to_receive' => 'warning',
            default => 'info',
        };

        // Update each notification individually to include mobile_order_id in href
        self::query()
            ->where('on_checkout_id', $checkoutId)
            ->get()
            ->each(function (self $notification) use ($hrefPrefix, $severity, $status) {
                $href = $notification->on_mobile_order_id
                    ? $hrefPrefix . '/' . $notification->on_mobile_order_id
                    : $hrefPrefix;

                $notification->update([
                    'on_status' => $status,
                    'on_href' => $href,
                    'on_severity' => $severity,
                ]);
            });
    }
}
