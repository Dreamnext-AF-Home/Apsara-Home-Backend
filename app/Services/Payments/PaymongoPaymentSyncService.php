<?php

namespace App\Services\Payments;

use App\Models\AdminNotification;
use App\Models\CheckoutHistory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymongoPaymentSyncService
{
    public function syncPendingOrders(int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));

        $orders = CheckoutHistory::query()
            ->whereNotNull('ch_checkout_id')
            ->where('ch_checkout_id', 'like', 'cs_%')
            ->where(function ($query) {
                $query->whereNull('ch_paid_at')
                    ->orWhereIn('ch_status', ['pending', 'active', 'unpaid']);
            })
            ->where('created_at', '>=', now()->subDays(2))
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
                $updated = $this->syncOrder($order);
                if ($updated) {
                    $summary['updated']++;
                } else {
                    $summary['skipped']++;
                }
            } catch (\Throwable $e) {
                $summary['errors'][] = sprintf(
                    'Order %s: %s',
                    (string) ($order->ch_checkout_id ?? '#' . (int) $order->ch_id),
                    $e->getMessage()
                );

                Log::warning('PayMongo payment sync failed for order.', [
                    'order_id' => (int) $order->ch_id,
                    'checkout_id' => (string) ($order->ch_checkout_id ?? ''),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    public function syncOrder(CheckoutHistory $order): bool
    {
        $checkoutId = trim((string) ($order->ch_checkout_id ?? ''));
        if ($checkoutId === '') {
            return false;
        }

        $paymentMode = $this->resolveCheckoutPaymentMode($checkoutId);
        $secretKey = $this->getPaymongoSecretKey($paymentMode);
        if ($secretKey === '') {
            return false;
        }

        $response = Http::withBasicAuth($secretKey, '')
            ->get($this->paymongoApiUrl("/v1/checkout_sessions/{$checkoutId}"));

        if ($response->failed()) {
            throw new \RuntimeException(sprintf(
                'PayMongo verify failed with status %s.',
                $response->status()
            ));
        }

        $attrs = $response->json('data.attributes');
        if (!is_array($attrs)) {
            return false;
        }

        $status = $this->resolveCheckoutStatusForStorage($attrs);
        $wasPaidBefore = $this->isPaidStatus($order->ch_status ?? null);
        $isNowPaid = $this->isPaidStatus($status);

        $order->ch_status = $status;
        $order->ch_payment_intent_id = data_get($attrs, 'payment_intent.id') ?: $order->ch_payment_intent_id;
        if ($isNowPaid && !$order->ch_paid_at) {
            $order->ch_paid_at = now();
        }
        $order->save();

        if ($isNowPaid && !$wasPaidBefore) {
            $this->createAdminOrderNotification($order);
            return true;
        }

        return false;
    }

    private function resolveCheckoutPaymentMode(string $checkoutId): string
    {
        $cachedCustomer = Cache::get("checkout_customer:{$checkoutId}");
        if (is_array($cachedCustomer) && in_array(($cachedCustomer['payment_mode'] ?? null), ['test', 'live'], true)) {
            return (string) $cachedCustomer['payment_mode'];
        }

        return app()->environment(['local', 'development', 'dev'])
            ? strtolower((string) config('services.paymongo.default_mode', 'test'))
            : 'live';
    }

    private function getPaymongoSecretKey(string $mode): string
    {
        $mode = in_array($mode, ['test', 'live'], true) ? $mode : 'live';
        return (string) config("services.paymongo.modes.{$mode}.secret_key", '');
    }

    private function paymongoApiUrl(string $path): string
    {
        $base = rtrim((string) config('services.paymongo.api_base_url', 'https://api.paymongo.com'), '/');
        return $base . '/' . ltrim($path, '/');
    }

    private function createAdminOrderNotification(CheckoutHistory $order): void
    {
        $customerName = trim((string) ($order->ch_customer_name ?? 'Customer'));
        $checkoutId = trim((string) ($order->ch_checkout_id ?? ''));
        $amount = (float) ($order->ch_amount ?? 0);

        AdminNotification::query()->firstOrCreate(
            [
                'an_type' => 'order_created',
                'an_source_type' => 'order',
                'an_source_id' => (int) $order->ch_id,
            ],
            [
                'an_severity' => 'warning',
                'an_title' => 'New Order Placed',
                'an_message' => sprintf(
                    '%s placed order %s (%s).',
                    $customerName !== '' ? $customerName : 'Customer',
                    $checkoutId !== '' ? $checkoutId : '#' . (int) $order->ch_id,
                    'PHP ' . number_format($amount, 2)
                ),
                'an_href' => '/admin/orders/pending',
                'an_payload' => [
                    'order_id' => (int) $order->ch_id,
                    'checkout_id' => $checkoutId,
                    'customer_name' => $customerName,
                    'amount' => $amount,
                ],
                'an_created_at' => now(),
            ]
        );
    }

    private function isPaidStatus(mixed $status): bool
    {
        if (!is_string($status)) {
            return false;
        }

        return in_array(strtolower($status), ['paid', 'succeeded', 'success'], true);
    }

    private function normalizeCheckoutStatusForStorage(mixed $status): string
    {
        if (!is_string($status)) {
            return 'pending';
        }

        $normalized = strtolower(trim($status));
        if (in_array($normalized, ['active', 'unpaid', 'pending'], true)) {
            return 'pending';
        }

        return $normalized;
    }

    private function resolveCheckoutStatusForStorage(array $attrs): string
    {
        $normalizedStatus = $this->normalizeCheckoutStatusForStorage($attrs['status'] ?? null);

        if ($this->isPaidStatus($normalizedStatus)) {
            return 'paid';
        }

        $paymentStatuses = array_filter([
            data_get($attrs, 'payment_intent.status'),
            data_get($attrs, 'payment_intent.attributes.status'),
            data_get($attrs, 'payment_intent.data.attributes.status'),
            data_get($attrs, 'payment.status'),
            data_get($attrs, 'payment.attributes.status'),
            ...$this->extractPaymentStatuses(data_get($attrs, 'payments')),
        ], static fn ($value) => is_string($value) && trim($value) !== '');

        foreach ($paymentStatuses as $paymentStatus) {
            if ($this->isPaidStatus($paymentStatus)) {
                return 'paid';
            }
        }

        return $normalizedStatus;
    }

    private function extractPaymentStatuses(mixed $payments): array
    {
        if (!is_array($payments)) {
            return [];
        }

        $statuses = [];
        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }

            $candidateStatuses = [
                $payment['status'] ?? null,
                data_get($payment, 'attributes.status'),
                data_get($payment, 'data.attributes.status'),
            ];

            foreach ($candidateStatuses as $candidateStatus) {
                if (is_string($candidateStatus) && trim($candidateStatus) !== '') {
                    $statuses[] = $candidateStatus;
                }
            }
        }

        return $statuses;
    }
}
