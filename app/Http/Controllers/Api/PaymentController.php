<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\Checkout\CheckoutCompletedMail;
use App\Models\CheckoutHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;


class PaymentController extends Controller
{
    private function mapMethods(string $method): array
    {
        return match ($method) {
            'card' => ['card'],
            'gcash' => ['gcash'],
            'maya' => ['paymaya'],
            'online_banking' => ['dob', 'ubp'], // adjust based on enabled methods in your PayMongo account
            default => ['gcash'],
        };
    }

    public function createCheckoutSession(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'description' => 'required|string|max:255',
            'payment_method' => 'required|in:online_banking,card,gcash,maya',

            'customer' => 'nullable|array',
            'customer.name' => 'nullable|string|max:255',
            'customer.email' => 'nullable|email|max:255',
            'customer.phone' => 'nullable|string|max:50',
            'customer.address' => 'nullable|string|max:500',
            'order' => 'nullable|array',
            'order.product_name' => 'nullable|string|max:255',
            'order.product_image' => 'nullable|string|max:1000',
            'order.quantity' => 'nullable|integer|min:1|max:1000',
            'order.selected_color' => 'nullable|string|max:100',
            'order.selected_size' => 'nullable|string|max:100',
            'order.selected_type' => 'nullable|string|max:100',
        ]);

        $secretKey = config('services.paymongo.secret_key');
        if (!$secretKey) {
            return response()->json(['message' => 'PAYMONGO_SECRET_KEY missing'], 500);
        }

        $frontend = env('FRONTEND_URL', 'http://localhost:3000');

        $payload = [
            'data' => [
                'attributes' => [
                    'line_items' => [[
                        'currency' => 'PHP',
                        'amount' => (int) round($validated['amount'] * 100), // centavos
                        'name' => $validated['description'],
                        'quantity' => 1,
                    ]],
                    'payment_method_types' => $this->mapMethods($validated['payment_method']),
                    'success_url' => $frontend . '/checkout/success',
                    'cancel_url' => $frontend . '/checkout/failed',
                    'description' => $validated['description'],
                ],
            ],
        ];

        $res = Http::withBasicAuth($secretKey, '')
            ->post('https://api.paymongo.com/v1/checkout_sessions', $payload);

        if ($res->failed()) {
            return response()->json([
                'message' => 'PayMongo create session failed',
                'error' => $res->json(),
            ], $res->status());
        }

        $data = $res->json('data');
        $checkoutId = $data['id'] ?? null;
        $customerId = auth('sanctum')->id();

        if ($checkoutId) {
            Cache::put("checkout_customer:{$checkoutId}", [
                'customer_id' => $customerId ? (int) $customerId : null,
                'name' => $validated['customer']['name'] ?? 'Customer',
                'email' => $validated['customer']['email'] ?? null,
                'phone' => $validated['customer']['phone'] ?? null,
                'address' => $validated['customer']['address'] ?? null,
                'description' => $validated['description'],
                'amount' => (float) $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'order' => $validated['order'] ?? [],
            ], now()->addDays(3));
        }

        return response()->json([
            'checkout_id' => $checkoutId,
            'checkout_url' => $data['attributes']['checkout_url'] ?? null,
        ]);
    }

    public function verifyCheckoutSession(Request $request, string $checkoutId)
    {
        $secretKey = config('services.paymongo.secret_key');
        if (!$secretKey) {
            return response()->json(['message' => 'PAYMONGO_SECRET_KEY missing'], 500);
        }

        $res = Http::withBasicAuth($secretKey, '')
            ->get("https://api.paymongo.com/v1/checkout_sessions/{$checkoutId}");

        if ($res->failed()) {
            return response()->json([
                'message' => 'PayMongo verify failed',
                'error' => $res->json(),
            ], $res->status());
        }

        $attrs = $res->json('data.attributes');
        $status = $attrs['status'] ?? null;

        $this->persistCheckoutHistoryIfNeeded($checkoutId, $attrs);

        if ($this->isPaidStatus($status)) {
            $this->sendCheckoutCompletedEmailIfNeeded($checkoutId, $attrs);
        }

        return response()->json([
            'checkout_id' => $checkoutId,
            'payment_intent_id' => $attrs['payment_intent']['id'] ?? null,
            'status' => $status, // usually paid / unpaid / failed
            'raw' => $attrs,
        ]);
    }

    private function sendCheckoutCompletedEmailIfNeeded(string $checkoutId, array $attrs): void
    {
        $customer = Cache::get("checkout_customer:{$checkoutId}");
        if (!$customer || empty($customer['email'])) return;

        $notifiedKey = "checkout_email_sent:{$checkoutId}";
        if (!Cache::add($notifiedKey, true, now()->addDays(7))) return;

        try {
            Mail::to($customer['email'])->send(new CheckoutCompletedMail([
                'checkout_id' => $checkoutId,
                'customer_name' => $customer['name'] ?? 'Customer',
                'description' => $customer['description'] ?? 'Order',
                'amount' => $customer['amount'] ?? 0,
                'payment_method' => $customer['payment_method'] ?? null,
                'status' => $attrs['status'] ?? 'paid',
                'payment_intent_id' => $attrs['payment_intent']['id'] ?? null,
            ]));
        } catch (\Throwable $e) {
            Cache::forget($notifiedKey);
            report($e);
        }
    }

    public function checkoutHistory(Request $request)
    {
        $customer = $request->user();
        if (!$customer) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $orders = CheckoutHistory::query()
            ->where('ch_customer_id', (int) $customer->getAuthIdentifier())
            ->orderByDesc('ch_paid_at')
            ->orderByDesc('ch_id')
            ->get()
            ->map(function (CheckoutHistory $order) {
                $quantity = max(1, (int) $order->ch_quantity);
                $itemName = $order->ch_product_name ?: ($order->ch_description ?: 'Order Item');
                $status = $order->ch_fulfillment_status
                    ? (string) $order->ch_fulfillment_status
                    : $this->mapCheckoutStatusToOrderStatus((string) $order->ch_status);

                return [
                    'id' => (int) $order->ch_id,
                    'order_number' => $order->ch_checkout_id,
                    'status' => $status,
                    'items' => [[
                        'id' => (int) $order->ch_id,
                        'name' => $itemName,
                        'image' => $order->ch_product_image ?: '/Images/HeroSection/sofas.jpg',
                        'quantity' => $quantity,
                        'price' => $quantity > 0 ? ((float) $order->ch_amount / $quantity) : (float) $order->ch_amount,
                    ]],
                    'total' => (float) $order->ch_amount,
                    'shipping_fee' => 0,
                    'payment_method' => $this->formatPaymentMethod((string) $order->ch_payment_method),
                    'shipping_address' => $order->ch_customer_address ?: 'No address provided',
                    'created_at' => optional($order->ch_paid_at ?? $order->created_at)->toDateTimeString(),
                    'estimated_delivery' => null,
                ];
            })
            ->values();

        return response()->json([
            'orders' => $orders,
        ]);
    }

    private function persistCheckoutHistoryIfNeeded(string $checkoutId, array $attrs): void
    {
        $cached = Cache::get("checkout_customer:{$checkoutId}");
        if (!$cached || empty($cached['customer_id'])) {
            return;
        }

        $order = is_array($cached['order'] ?? null) ? $cached['order'] : [];
        $quantity = (int) ($order['quantity'] ?? 1);
        $quantity = $quantity > 0 ? $quantity : 1;
        $existingFulfillmentStatus = CheckoutHistory::query()
            ->where('ch_checkout_id', $checkoutId)
            ->value('ch_fulfillment_status');
        $existingApprovalStatus = CheckoutHistory::query()
            ->where('ch_checkout_id', $checkoutId)
            ->value('ch_approval_status');

        CheckoutHistory::updateOrCreate(
            ['ch_checkout_id' => $checkoutId],
            [
                'ch_customer_id' => (int) $cached['customer_id'],
                'ch_payment_intent_id' => data_get($attrs, 'payment_intent.id'),
                'ch_status' => (string) ($attrs['status'] ?? 'paid'),
                'ch_description' => (string) ($cached['description'] ?? ''),
                'ch_amount' => (float) ($cached['amount'] ?? 0),
                'ch_payment_method' => (string) ($cached['payment_method'] ?? ''),
                'ch_quantity' => $quantity,
                'ch_product_name' => (string) ($order['product_name'] ?? ($cached['description'] ?? 'Order Item')),
                'ch_product_image' => (string) ($order['product_image'] ?? ''),
                'ch_selected_color' => (string) ($order['selected_color'] ?? ''),
                'ch_selected_size' => (string) ($order['selected_size'] ?? ''),
                'ch_selected_type' => (string) ($order['selected_type'] ?? ''),
                'ch_customer_name' => (string) ($cached['name'] ?? 'Customer'),
                'ch_customer_email' => (string) ($cached['email'] ?? ''),
                'ch_customer_phone' => (string) ($cached['phone'] ?? ''),
                'ch_customer_address' => (string) ($cached['address'] ?? ''),
                'ch_paid_at' => $this->isPaidStatus($attrs['status'] ?? null) ? now() : null,
                'ch_approval_status' => $existingApprovalStatus ?: 'pending_approval',
                'ch_fulfillment_status' => $existingFulfillmentStatus ?: 'pending',
            ]
        );
    }

    private function isPaidStatus(mixed $status): bool
    {
        return is_string($status) && strtolower($status) === 'paid';
    }

    private function mapCheckoutStatusToOrderStatus(string $status): string
    {
        return match (strtolower($status)) {
            'paid', 'succeeded', 'success' => 'processing',
            'failed', 'cancelled', 'expired' => 'cancelled',
            'active', 'unpaid', 'pending' => 'pending',
            default => 'processing',
        };
    }

    private function formatPaymentMethod(string $method): string
    {
        return match (strtolower($method)) {
            'gcash' => 'GCash',
            'maya', 'paymaya' => 'Maya',
            'card' => 'Credit / Debit Card',
            'online_banking' => 'Online Banking',
            default => ucfirst(str_replace('_', ' ', $method)),
        };
    }
}
