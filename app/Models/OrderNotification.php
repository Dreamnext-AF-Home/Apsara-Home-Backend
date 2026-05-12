<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;

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
        // Normalize checkout_id - trim whitespace and lowercase for comparison
        $checkoutId = trim($checkoutId);

        Log::info('Updating order notification status', [
            'checkout_id' => $checkoutId,
            'status' => $status,
            'checkout_id_length' => strlen($checkoutId),
        ]);

        $hrefPrefix = match ($status) {
            'pending' => 'purchases://pending',
            'paid', 'succeeded', 'success' => 'purchases://paid',
            'processing' => 'purchases://processing',
            'to_ship', 'packed', 'shipped' => 'purchases://to_ship',
            'to_receive', 'out_for_delivery' => 'purchases://to_receive',
            'delivered', 'completed' => 'purchases://delivered',
            default => 'purchases://pending',
        };

        $severity = match ($status) {
            'paid', 'succeeded', 'success' => 'success',
            'delivered', 'completed' => 'success',
            'to_ship', 'packed', 'shipped', 'to_receive', 'out_for_delivery' => 'warning',
            default => 'info',
        };

        // Find notifications by checkout_id
        $notifications = self::query()
            ->where('on_checkout_id', $checkoutId)
            ->get();

        Log::info('Found notifications to update', [
            'checkout_id' => $checkoutId,
            'count' => $notifications->count(),
        ]);

        if ($notifications->isEmpty()) {
            Log::warning('No notifications found for checkout_id', [
                'checkout_id' => $checkoutId,
                'all_notifications_count' => self::query()->count(),
                'sample_checkout_ids' => self::query()->limit(3)->pluck('on_checkout_id')->toArray(),
            ]);
            return;
        }

        // Track customer IDs for broadcasting
        $customerIds = [];

        // Update each notification
        foreach ($notifications as $notification) {
            $href = $notification->on_checkout_id
                ? $hrefPrefix . '/' . $notification->on_checkout_id
                : $hrefPrefix;

            // Build dynamic message based on status and notification details
            $productName = $notification->on_product_name ?? 'your item';
            $amount = number_format((float) ($notification->on_amount ?? 0), 2);
            $paymentMethod = ucfirst($notification->on_payment_method ?? 'the payment method');

            $message = match ($status) {
                'paid', 'succeeded', 'success' => "Payment confirmed via {$paymentMethod}! Your order amounting to ₱{$amount} has been paid and is being processed.",
                'processing' => "Your order {$productName} is now being prepared for shipment.",
                'to_ship', 'packed', 'shipped' => "Your order {$productName} is now ready to ship.",
                'to_receive', 'out_for_delivery' => "Your order {$productName} is out for delivery and will arrive soon.",
                'delivered', 'completed' => "Your order {$productName} has been delivered. Thank you for shopping!",
                default => null, // Keep existing message for other statuses
            };

            $updateData = [
                'on_status' => $status,
                'on_href' => $href,
                'on_severity' => $severity,
            ];

            // Update message if we have a specific one for this status
            if ($message !== null) {
                $updateData['on_message'] = $message;
            }

            $updated = $notification->update($updateData);

            Log::info('Order notification update result', [
                'notification_id' => $notification->on_id,
                'checkout_id' => $checkoutId,
                'update_success' => $updated,
                'update_data' => $updateData,
            ]);

            $customerIds[] = (int) $notification->on_customer_id;
        }

        // Broadcast updates to affected customers
        foreach (array_unique($customerIds) as $customerId) {
            self::broadcastStatusUpdate($customerId, $checkoutId, $status);
        }
    }

    private static function broadcastStatusUpdate(int $customerId, string $checkoutId, string $status): void
    {
        try {
            $key = (string) config('services.pusher.key', '');
            $secret = (string) config('services.pusher.secret', '');
            $appId = (string) config('services.pusher.app_id', '');
            $cluster = (string) config('services.pusher.cluster', 'ap1');

            if ($key === '' || $secret === '' || $appId === '') {
                return;
            }

            $pusher = new Pusher($key, $secret, $appId, ['cluster' => $cluster, 'useTLS' => true]);
            $channelName = 'private-customer-' . $customerId;

            $unreadCount = self::query()
                ->where('on_customer_id', $customerId)
                ->where('on_is_read', false)
                ->count();

            // Get the notification message from the first notification
            $notification = self::query()
                ->where('on_checkout_id', $checkoutId)
                ->where('on_customer_id', $customerId)
                ->first();

            $pusher->trigger($channelName, 'order.notification.updated', [
                'checkout_id' => $checkoutId,
                'status' => $status,
                'message' => $notification?->on_message ?? "Order status updated to: {$status}",
                'title' => $notification?->on_title ?? 'Order Status Updated',
                'unread_count' => (int) $unreadCount,
                'updated_at' => now()->toDateTimeString(),
            ]);

            $pusher->trigger($channelName, 'notification.count.updated', [
                'unread_count' => (int) $unreadCount,
                'updated_at' => now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to broadcast notification status update', [
                'customer_id' => $customerId,
                'checkout_id' => $checkoutId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
