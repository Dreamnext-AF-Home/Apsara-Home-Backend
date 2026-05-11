<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerNotification;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;

class CustomerBonusNotification
{
    public static function notify(
        Customer $customer,
        string $type,
        string $title,
        string $message,
        string $sourceType,
        int $sourceId,
        array $payload = [],
        string $href = '/profile?tab=encashment',
        string $severity = 'success'
    ): void {
        $customerId = (int) ($customer->c_userid ?? 0);
        if ($customerId <= 0 || $sourceId <= 0) {
            return;
        }

        $createdAt = now('Asia/Manila');

        $notification = CustomerNotification::query()->firstOrCreate(
            [
                'cn_customer_id' => $customerId,
                'cn_source_type' => $sourceType,
                'cn_source_id' => $sourceId,
            ],
            [
                'cn_type' => $type,
                'cn_severity' => $severity,
                'cn_title' => $title,
                'cn_message' => $message,
                'cn_href' => $href,
                'cn_payload' => $payload,
                'cn_created_at' => $createdAt,
            ]
        );

        if (!$notification->wasRecentlyCreated) {
            return;
        }

        self::publishRealtime($customerId, [
            'id' => 'customer_notification:' . (int) $notification->cn_id,
            'type' => $type,
            'title' => $title,
            'description' => $message,
            'count' => 1,
            'severity' => $severity,
            'href' => $href,
            'latest_at' => $notification->cn_created_at
                ? $notification->cn_created_at->timezone('Asia/Manila')->toIso8601String()
                : null,
            'created_at' => $notification->cn_created_at
                ? $notification->cn_created_at->timezone('Asia/Manila')->toIso8601String()
                : null,
            'payload' => $payload,
        ]);
    }

    private static function publishRealtime(int $customerId, array $payload): void
    {
        $appId = (string) config('services.pusher.app_id', '');
        $key = (string) config('services.pusher.key', '');
        $secret = (string) config('services.pusher.secret', '');

        if ($appId === '' || $key === '' || $secret === '') {
            return;
        }

        try {
            $pusher = new Pusher($key, $secret, $appId, [
                'cluster' => (string) config('services.pusher.cluster', 'ap1'),
                'useTLS' => (bool) config('services.pusher.use_tls', true),
            ]);

            $pusher->trigger('private-customer-' . $customerId, 'notification.created', $payload);
        } catch (\Throwable $exception) {
            Log::warning('Failed to publish customer bonus notification.', [
                'customer_id' => $customerId,
                'notification_id' => $payload['id'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
