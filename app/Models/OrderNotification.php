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
        'on_parent_notification_id',
        'on_notification_group_id',
        'on_type',
        'on_event_type',
        'on_severity',
        'on_priority',
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
        'on_is_parent',
        'on_read_at',
        'on_event_date',
        'on_created_at',
    ];

    protected $casts = [
        'on_customer_id' => 'integer',
        'on_parent_notification_id' => 'integer',
        'on_quantity' => 'integer',
        'on_amount' => 'decimal:2',
        'on_payload' => 'array',
        'on_is_read' => 'boolean',
        'on_is_parent' => 'boolean',
        'on_read_at' => 'datetime',
        'on_event_date' => 'datetime',
        'on_created_at' => 'datetime',
    ];

    public function markAsRead(): void
    {
        $this->update([
            'on_is_read' => true,
            'on_read_at' => now(),
        ]);
    }

    public function parentNotification()
    {
        return $this->belongsTo(OrderNotification::class, 'on_parent_notification_id', 'on_id');
    }

    public function childNotifications()
    {
        return $this->hasMany(OrderNotification::class, 'on_parent_notification_id', 'on_id')
            ->orderBy('on_event_date', 'asc')
            ->orderBy('on_created_at', 'asc');
    }

    public static function createParentNotification(int $customerId, string $checkoutId, string $groupId, array $data = []): self
    {
        return self::create([
            'on_customer_id' => $customerId,
            'on_checkout_id' => $checkoutId,
            'on_notification_group_id' => $groupId,
            'on_is_parent' => true,
            'on_type' => 'order_created',
            'on_event_type' => 'order_placed',
            'on_priority' => 'HIGH',
            'on_severity' => 'info',
            'on_status' => 'pending',
            'on_title' => $data['title'] ?? 'Order Placed ✓',
            'on_message' => $data['message'] ?? 'Your order has been placed successfully',
            'on_product_name' => $data['product_name'] ?? null,
            'on_product_image' => $data['product_image'] ?? null,
            'on_amount' => $data['amount'] ?? 0,
            'on_payment_method' => $data['payment_method'] ?? null,
            'on_href' => $data['href'] ?? 'purchases://pending/' . $checkoutId,
            'on_payload' => $data['payload'] ?? null,
            'on_event_date' => now(),
            'on_created_at' => now(),
        ]);
    }

    public static function createChildNotification(int $parentNotificationId, int $customerId, string $checkoutId, string $groupId, array $data = []): self
    {
        return self::create([
            'on_customer_id' => $customerId,
            'on_checkout_id' => $checkoutId,
            'on_parent_notification_id' => $parentNotificationId,
            'on_notification_group_id' => $groupId,
            'on_is_parent' => false,
            'on_type' => $data['type'] ?? 'order_updated',
            'on_event_type' => $data['event_type'] ?? 'status_updated',
            'on_priority' => $data['priority'] ?? 'MEDIUM',
            'on_severity' => $data['severity'] ?? 'info',
            'on_status' => $data['status'] ?? 'pending',
            'on_title' => $data['title'] ?? 'Order Updated',
            'on_message' => $data['message'] ?? 'Your order status has been updated',
            'on_product_name' => $data['product_name'] ?? null,
            'on_product_image' => $data['product_image'] ?? null,
            'on_amount' => $data['amount'] ?? null,
            'on_payment_method' => $data['payment_method'] ?? null,
            'on_href' => $data['href'] ?? null,
            'on_payload' => $data['payload'] ?? null,
            'on_event_date' => $data['event_date'] ?? now(),
            'on_created_at' => now(),
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

        // Find parent notifications by checkout_id
        $parentNotifications = self::query()
            ->where('on_checkout_id', $checkoutId)
            ->where('on_is_parent', true)
            ->get();

        Log::info('Found parent notifications to update', [
            'checkout_id' => $checkoutId,
            'count' => $parentNotifications->count(),
        ]);

        if ($parentNotifications->isEmpty()) {
            Log::warning('No parent notifications found for checkout_id', [
                'checkout_id' => $checkoutId,
                'all_notifications_count' => self::query()->count(),
                'sample_checkout_ids' => self::query()->limit(3)->pluck('on_checkout_id')->toArray(),
            ]);
            return;
        }

        // Track customer IDs for broadcasting
        $customerIds = [];

        // Build dynamic title with status and emoji
        $statusEmoji = match ($status) {
            'paid', 'succeeded', 'success' => '✅',
            'processing' => '⚙️',
            'to_ship', 'packed' => '📦',
            'shipped' => '🚚',
            'to_receive', 'out_for_delivery' => '🚗',
            'delivered', 'completed' => '✨',
            'cancelled' => '❌',
            'refunded' => '💰',
            default => '📋',
        };

        $statusLabel = match ($status) {
            'paid', 'succeeded', 'success' => 'Payment Confirmed',
            'processing' => 'Processing',
            'to_ship', 'packed' => 'Ready to Ship',
            'shipped' => 'Shipped',
            'to_receive', 'out_for_delivery' => 'Out for Delivery',
            'delivered', 'completed' => 'Delivered',
            'cancelled' => 'Cancelled',
            'refunded' => 'Refunded',
            default => 'Order Updated',
        };

        $eventType = match ($status) {
            'paid', 'succeeded', 'success' => 'payment_confirmed',
            'processing' => 'processing',
            'to_ship', 'packed' => 'ready_to_ship',
            'shipped' => 'shipped',
            'to_receive', 'out_for_delivery' => 'out_for_delivery',
            'delivered', 'completed' => 'delivered',
            default => 'status_updated',
        };

        // Update each parent notification and create child notification
        foreach ($parentNotifications as $parentNotification) {
            $href = $parentNotification->on_checkout_id
                ? $hrefPrefix . '/' . $parentNotification->on_checkout_id
                : $hrefPrefix;

            // Build dynamic message based on status and notification details
            $productName = $parentNotification->on_product_name ?? 'your item';
            $amount = number_format((float) ($parentNotification->on_amount ?? 0), 2);
            $paymentMethod = ucfirst($parentNotification->on_payment_method ?? 'the payment method');

            $title = "Order: {$statusLabel} {$statusEmoji}";

            $message = match ($status) {
                'paid', 'succeeded', 'success' => "Payment confirmed via {$paymentMethod}! Your order amounting to ₱{$amount} has been paid and is being processed.",
                'processing' => "Your order {$productName} is now being prepared for shipment.",
                'to_ship', 'packed', 'shipped' => "Your order {$productName} is now ready to ship.",
                'to_receive', 'out_for_delivery' => "Your order {$productName} is out for delivery and will arrive soon.",
                'delivered', 'completed' => "Your order {$productName} has been delivered. Thank you for shopping!",
                default => null,
            };

            $updateData = [
                'on_status' => $status,
                'on_href' => $href,
                'on_severity' => $severity,
                'on_title' => $title,
            ];

            if ($message !== null) {
                $updateData['on_message'] = $message;
            }

            $updated = $parentNotification->update($updateData);

            Log::info('Order notification update result', [
                'notification_id' => $parentNotification->on_id,
                'checkout_id' => $checkoutId,
                'update_success' => $updated,
                'update_data' => $updateData,
            ]);

            // Create child notification for this status update
            self::createChildNotification(
                $parentNotification->on_id,
                $parentNotification->on_customer_id,
                $checkoutId,
                $parentNotification->on_notification_group_id ?? $checkoutId,
                [
                    'type' => 'order_updated',
                    'event_type' => $eventType,
                    'status' => $status,
                    'priority' => 'MEDIUM',
                    'severity' => $severity,
                    'title' => $statusLabel . ' ' . $statusEmoji,
                    'message' => $message ?? "Order status updated to: {$statusLabel}",
                    'product_name' => $productName,
                    'product_image' => $parentNotification->on_product_image,
                    'amount' => $parentNotification->on_amount,
                    'payment_method' => $parentNotification->on_payment_method,
                    'href' => $href,
                    'event_date' => now(),
                ]
            );

            $customerIds[] = (int) $parentNotification->on_customer_id;
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
