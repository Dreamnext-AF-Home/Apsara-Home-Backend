<?php

namespace App\Services\Zq;

use App\Models\AdminNotification;
use App\Models\CheckoutHistory;
use App\Models\CustomerNotification;
use Illuminate\Support\Facades\Log;

class ZqTrackingSyncService
{
    public function __construct(
        private readonly ZqApiService $zqApiService
    ) {
    }

    public function syncPendingOrders(int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));

        if (! $this->zqApiService->isConfigured()) {
            return [
                'processed' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => ['ZQ API is not configured.'],
            ];
        }

        $orders = CheckoutHistory::query()
            ->where('ch_approval_status', 'approved')
            ->whereNotNull('ch_zq_platform_order_id')
            ->where('ch_zq_platform_order_id', '!=', '')
            ->where(function ($query) {
                $query->whereNull('ch_tracking_no')
                    ->orWhere('ch_tracking_no', '');
            })
            ->orderByRaw('CASE WHEN ch_zq_synced_at IS NULL THEN 0 ELSE 1 END ASC')
            ->orderBy('ch_zq_synced_at')
            ->orderByDesc('ch_id')
            ->limit($limit)
            ->get();

        $summary = [
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($orders as $order) {
            $summary['processed']++;

            try {
                $result = $this->syncOrder($order);

                if ($result['updated'] ?? false) {
                    $summary['updated']++;
                } else {
                    $summary['skipped']++;
                }
            } catch (\Throwable $e) {
                $summary['errors'][] = sprintf(
                    'Order #%d (%s): %s',
                    (int) $order->ch_id,
                    (string) ($order->ch_checkout_id ?? 'n/a'),
                    $e->getMessage()
                );

                Log::warning('ZQ tracking sync failed for order.', [
                    'order_id' => (int) $order->ch_id,
                    'checkout_id' => (string) ($order->ch_checkout_id ?? ''),
                    'zq_platform_order_id' => (string) ($order->ch_zq_platform_order_id ?? ''),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    public function syncOrder(CheckoutHistory $order): array
    {
        $platformOrderId = trim((string) ($order->ch_zq_platform_order_id ?? ''));
        if ($platformOrderId === '') {
            return ['updated' => false, 'tracking_no' => null];
        }

        $previousTrackingNo = trim((string) ($order->ch_tracking_no ?? ''));
        $response = $this->zqApiService->getTracking($platformOrderId);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];

        $trackingNo = $this->normalizeStringValue($data['trackNumber'] ?? '');
        $trackingNoFirstMile = $this->normalizeStringValue($data['trackNumber1'] ?? '');
        $resolvedTrackingNo = $trackingNo !== '' ? $trackingNo : $trackingNoFirstMile;

        $shipmentPayload = is_array($order->ch_shipment_payload) ? $order->ch_shipment_payload : [];
        $shipmentPayload['zq_tracking'] = $response;

        $order->fill([
            'ch_zq_platform_order_id' => $this->normalizeStringValue($data['platformOrderId'] ?? $platformOrderId) ?: $platformOrderId,
            'ch_shipment_payload' => $shipmentPayload,
            'ch_zq_response' => $response,
            'ch_zq_synced_at' => now(),
        ]);

        $trackingWasAdded = false;

        if ($resolvedTrackingNo !== '') {
            $trackingWasAdded = $previousTrackingNo === '' || $previousTrackingNo !== $resolvedTrackingNo;

            $order->ch_courier = 'zq';
            $order->ch_tracking_no = $resolvedTrackingNo;
            $order->ch_shipment_status = 'in_transit';
            if (! in_array((string) ($order->ch_fulfillment_status ?? ''), ['delivered', 'out_for_delivery'], true)) {
                $order->ch_fulfillment_status = 'shipped';
            }
            $order->ch_shipped_at = $order->ch_shipped_at ?: now();
        }

        $order->save();

        if ($trackingWasAdded) {
            $this->createTrackingNotifications($order, $resolvedTrackingNo);
        }

        return [
            'updated' => $trackingWasAdded,
            'tracking_no' => $resolvedTrackingNo !== '' ? $resolvedTrackingNo : null,
        ];
    }

    private function createTrackingNotifications(CheckoutHistory $order, string $trackingNo): void
    {
        $checkoutId = trim((string) ($order->ch_checkout_id ?? ''));
        $customerId = (int) ($order->ch_customer_id ?? 0);

        AdminNotification::query()->firstOrCreate(
            [
                'an_type' => 'zq_tracking_available',
                'an_source_type' => 'order',
                'an_source_id' => (int) $order->ch_id,
            ],
            [
                'an_severity' => 'info',
                'an_title' => 'ZQ Tracking Ready',
                'an_message' => sprintf(
                    'Tracking number %s is now available for order %s.',
                    $trackingNo,
                    $checkoutId !== '' ? $checkoutId : '#' . (int) $order->ch_id
                ),
                'an_href' => '/admin/orders',
                'an_payload' => [
                    'order_id' => (int) $order->ch_id,
                    'checkout_id' => $checkoutId,
                    'tracking_no' => $trackingNo,
                    'courier' => 'zq',
                ],
                'an_created_at' => now(),
            ]
        );

        if ($customerId > 0) {
            CustomerNotification::query()->firstOrCreate(
                [
                    'cn_type' => 'order_tracking',
                    'cn_source_type' => 'order',
                    'cn_source_id' => (int) $order->ch_id,
                    'cn_customer_id' => $customerId,
                ],
                [
                    'cn_severity' => 'info',
                    'cn_title' => 'Order Tracking Update',
                    'cn_message' => sprintf(
                        'Your order %s now has tracking number %s.',
                        $checkoutId !== '' ? $checkoutId : '#' . (int) $order->ch_id,
                        $trackingNo
                    ),
                    'cn_href' => '/orders',
                    'cn_payload' => [
                        'order_id' => (int) $order->ch_id,
                        'checkout_id' => $checkoutId,
                        'tracking_no' => $trackingNo,
                        'courier' => 'zq',
                    ],
                    'cn_created_at' => now(),
                ]
            );
        }
    }

    private function normalizeStringValue(mixed $value): string
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $normalized = $this->normalizeStringValue($item);
                if ($normalized !== '') {
                    return $normalized;
                }
            }

            return '';
        }

        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }
}
