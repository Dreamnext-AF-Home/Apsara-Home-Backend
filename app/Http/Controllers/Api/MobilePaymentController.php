<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckoutHistory;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobilePaymentController extends Controller
{
    private const PLATFORMS = ['ios', 'android'];
    private const MAX_MOBILE_PAYMENT_ATTEMPTS = 3;

    public function createMobilePayment(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'description' => 'required|string|max:255',
            'payment_method' => 'required|in:online_banking,card,gcash,maya',
            'payment_mode' => 'nullable|in:test,live',
            'online_banking_provider' => 'nullable|in:dob,ubp',
            'voucher_code' => 'nullable|string|max:80',
            'idempotency_key' => 'nullable|string|max:255',

            // Mobile-specific required fields
            'platform' => 'required|in:ios,android',
            'app_version' => 'required|string|max:50',
            'device_id' => 'nullable|string|max:255',

            // Customer info
            'customer' => 'nullable|array',
            'customer.name' => 'nullable|string|max:255',
            'customer.email' => 'nullable|email|max:255',
            'customer.phone' => 'nullable|string|max:50',
            'customer.address' => 'nullable|string|max:500',
            'customer.referred_by' => 'nullable|string|max:255',
            'customer.is_member' => 'nullable|boolean',

            // Order info
            'order' => 'nullable|array',
            'order.product_name' => 'nullable|string|max:255',
            'order.product_id' => 'nullable|integer|min:1',
            'order.product_sku' => 'nullable|string|max:100',
            'order.product_pv' => 'nullable|numeric|min:0',
            'order.product_image' => 'nullable|string|max:1000',
            'order.quantity' => 'nullable|integer|min:1|max:1000',
            'order.selected_color' => 'nullable|string|max:100',
            'order.selected_size' => 'nullable|string|max:100',
            'order.selected_type' => 'nullable|string|max:100',
            'order.subtotal' => 'nullable|numeric|min:0',
            'order.handling_fee' => 'nullable|numeric|min:0',
        ]);

        try {
            $customer = $request->user();
            $idempotencyKey = $validated['idempotency_key'] ?? null;

            // Check for duplicate pending order with idempotency key or duplicate detection
            $existingOrder = $this->checkForDuplicateOrder($customer, $validated, $idempotencyKey);
            if ($existingOrder) {
                return response()->json([
                    'mobile_order_id' => $existingOrder->ch_mobile_order_id,
                    'checkout_id' => $existingOrder->ch_checkout_id,
                    'checkout_url' => $this->getCheckoutUrlFromCache($existingOrder->ch_mobile_order_id),
                    'payment_mode' => $this->resolvePaymentMode($validated['payment_mode'] ?? null),
                    'platform' => $validated['platform'],
                    'status' => 'pending',
                    'created_at' => $existingOrder->created_at->toISOString(),
                    'is_duplicate' => true,
                ]);
            }

            // Generate unique mobile order ID
            $mobileOrderId = $this->generateMobileOrderId($validated['platform']);

            // Create PayMongo checkout session FIRST
            $paymongoResponse = $this->createPayMongoCheckoutSession($validated, $mobileOrderId);

            // Check if MOBILE order was already created (should not happen in normal flow)
            $existingMobileOrder = CheckoutHistory::query()
                ->where('ch_checkout_id', $paymongoResponse['checkout_id'])
                ->where('ch_is_mobile', true)
                ->first();

            if ($existingMobileOrder) {
                // Mobile order already exists, reuse it
                $mobileOrder = $existingMobileOrder;
            } else {
                // Create mobile order record with checkout ID
                $mobileOrder = $this->createMobileOrder($request, $validated, $mobileOrderId, $paymongoResponse, $idempotencyKey);
            }

            // Cache mobile order data for payment verification
            $this->cacheMobileOrderData($mobileOrderId, $validated, $mobileOrder);

            return response()->json([
                'order_id' => (int) $mobileOrder->ch_id,
                'mobile_order_id' => $mobileOrderId,
                'checkout_id' => $paymongoResponse['checkout_id'],
                'checkout_url' => $paymongoResponse['checkout_url'],
                'payment_mode' => $paymongoResponse['payment_mode'],
                'platform' => $validated['platform'],
                'created_at' => now()->toISOString(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Mobile payment processing failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getMobilePaymentStatus(Request $request, string $mobileOrderId)
    {
        $order = CheckoutHistory::query()
            ->where('ch_mobile_order_id', $mobileOrderId)
            ->where('ch_customer_id', (int) $request->user()->getAuthIdentifier())
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Mobile order not found'], 404);
        }

        // Verify payment status with PayMongo if needed
        $status = $order->ch_status;
        if ($order->ch_checkout_id && $status === 'pending') {
            $status = $this->verifyPayMongoPaymentStatus($order->ch_checkout_id);
            $order->update(['ch_status' => $status]);
        }

        return response()->json([
            'mobile_order_id' => $mobileOrderId,
            'checkout_id' => $order->ch_checkout_id,
            'status' => $status,
            'platform' => $order->ch_platform,
            'amount' => $order->ch_amount,
            'paid_at' => $order->ch_paid_at?->toISOString(),
            'updated_at' => $order->updated_at->toISOString(),
        ]);
    }

    public function proceedWithPendingPayment(Request $request, string $checkoutId)
    {
        $customer = $request->user();

        // Get the EXISTING pending order using checkout_id
        $order = CheckoutHistory::where('ch_checkout_id', $checkoutId)
            ->where('ch_customer_id', (int) $customer->getAuthIdentifier())
            ->where('ch_status', 'pending')
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Pending order not found'], 404);
        }

        // Get checkout URL from PayMongo
        $checkoutUrl = $this->getCheckoutUrlFromPayMongo($checkoutId);

        return response()->json([
            'checkout_id' => $checkoutId,
            'checkout_url' => $checkoutUrl,
            'status' => 'pending',
            'amount' => (float) $order->ch_amount,
            'shipping_fee' => (float) ($order->ch_shipping_fee ?? 0),
            'product_name' => $order->ch_product_name,
            'quantity' => (int) $order->ch_quantity,
            'created_at' => $order->created_at->toISOString(),
        ]);
    }

    public function getMobileOrderHistory(Request $request)
    {
        $customer = $request->user();
        $platform = $request->query('platform'); // Optional platform filter

        $orders = CheckoutHistory::query()
            ->where('ch_customer_id', (int) $customer->getAuthIdentifier())
            ->where('ch_is_mobile', true)
            ->when($platform, function ($query, $platform) {
                $query->where('ch_platform', $platform);
            })
            ->orderByRaw('COALESCE(ch_paid_at, created_at) DESC')
            ->orderByDesc('ch_id')
            ->get()
            ->map(function (CheckoutHistory $order) {
                $paymentStatus = match(strtolower($order->ch_status)) {
                    'paid', 'succeeded', 'success' => 'paid',
                    'failed', 'cancelled', 'expired' => 'cancelled',
                    'active', 'unpaid', 'pending' => 'pending',
                    default => 'pending',
                };
                $fulfillmentStatus = $order->ch_fulfillment_status ?: 'pending';
                $displayStatus = $fulfillmentStatus !== 'pending' ? $fulfillmentStatus : $paymentStatus;

                return [
                    'id' => (int) $order->ch_id,
                    'mobile_order_id' => $order->ch_mobile_order_id,
                    'order_number' => $order->ch_checkout_id,
                    'status' => $displayStatus,
                    'payment_status' => $paymentStatus,
                    'fulfillment_status' => $fulfillmentStatus,
                    'platform' => $order->ch_platform,
                    'app_version' => $order->ch_app_version,
                    'items' => [[
                        'id' => (int) $order->ch_id,
                        'product_id' => $order->ch_product_id ? (int) $order->ch_product_id : null,
                        'name' => $order->ch_product_name ?: ($order->ch_description ?: 'Order Item'),
                        'image' => $order->ch_product_image ?: '/Images/HeroSection/sofas.jpg',
                        'quantity' => max(1, (int) $order->ch_quantity),
                        'price' => max(0, (float) $order->ch_amount - (float) ($order->ch_shipping_fee ?? 0)) / max(1, (int) $order->ch_quantity),
                        'selected_color' => $order->ch_selected_color,
                        'selected_size' => $order->ch_selected_size,
                        'selected_type' => $order->ch_selected_type,
                    ]],
                    'total_amount' => (float) $order->ch_amount,
                    'shipping_fee' => (float) ($order->ch_shipping_fee ?? 0),
                    'payment_method' => $this->formatPaymentMethod((string) ($order->ch_payment_method ?? '')),
                    'tracking_number' => $this->resolveOrderTrackingNumber($order),
                    'created_at' => optional($order->ch_paid_at ?? $order->created_at)->toDateTimeString(),
                ];
            });

        return response()->json([
            'orders' => $orders,
            'total' => count($orders),
            'platform' => $platform,
        ]);
    }

    private function checkForDuplicateOrder($customer, array $validated, ?string $idempotencyKey): ?CheckoutHistory
    {
        if ($idempotencyKey) {
            $order = CheckoutHistory::where('ch_customer_id', (int) $customer->getAuthIdentifier())
                ->whereJsonContains('ch_mobile_metadata->idempotency_key', $idempotencyKey)
                ->where('ch_is_mobile', true)
                ->whereIn('ch_status', ['pending', 'paid', 'succeeded', 'success'])
                ->first();

            if ($order) {
                return $order;
            }
        }

        // Fallback: check for duplicate within last 5 minutes with same product, amount, customer
        $orderData = $validated['order'] ?? [];
        $fiveMinutesAgo = now()->subMinutes(5);

        $duplicate = CheckoutHistory::where('ch_customer_id', (int) $customer->getAuthIdentifier())
            ->where('ch_product_id', $orderData['product_id'] ?? null)
            ->where('ch_amount', (float) $validated['amount'])
            ->whereIn('ch_status', ['pending', 'paid', 'succeeded', 'success'])
            ->where('ch_is_mobile', true)
            ->where('created_at', '>=', $fiveMinutesAgo)
            ->latest('created_at')
            ->first();

        return $duplicate;
    }

    private function getCheckoutUrlFromCache(string $mobileOrderId): ?string
    {
        $cached = Cache::get("mobile_order:{$mobileOrderId}");
        return $cached['checkout_url'] ?? null;
    }

    private function getCheckoutUrlFromPayMongo(string $checkoutId): ?string
    {
        try {
            $paymongoConfig = $this->getPaymongoConfig();
            $secretKey = $paymongoConfig['secret_key'];

            $response = Http::withBasicAuth($secretKey, '')
                ->get($this->paymongoApiUrl("/v1/checkout_sessions/{$checkoutId}", $paymongoConfig['mode']));

            if ($response->successful()) {
                return $response->json('data.attributes.checkout_url');
            }

            Log::warning('Failed to fetch checkout URL from PayMongo', [
                'checkout_id' => $checkoutId,
                'response' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('PayMongo checkout URL fetch error', [
                'checkout_id' => $checkoutId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function resolvePaymentMode(?string $requestedMode): string
    {
        return $this->resolveRequestedPaymongoMode($requestedMode);
    }

    private function checkMobilePaymentRateLimit(Request $request): void
    {
        $key = "mobile_payment_rate_limit:" . $request->ip();
        $attempts = Cache::get($key, 0);
        
        if ($attempts >= self::MAX_MOBILE_PAYMENT_ATTEMPTS) {
            throw ValidationException::withMessages([
                'rate_limit' => ['Too many payment attempts. Please try again later.'],
            ]);
        }
        
        Cache::put($key, $attempts + 1, now()->addMinutes(15));
    }

    private function generateMobileOrderId(string $platform): string
    {
        $prefix = $platform === 'ios' ? 'IOS' : 'AND';
        $timestamp = now()->format('YmdHis');
        $random = strtoupper(Str::random(6));
        
        return "{$prefix}-{$timestamp}-{$random}";
    }

    private function createMobileOrder(Request $request, array $validated, string $mobileOrderId, array $paymongoResponse, ?string $idempotencyKey = null): CheckoutHistory
    {
        $customer = $request->user();
        $orderData = $validated['order'] ?? [];
        $customerData = $validated['customer'] ?? [];

        return CheckoutHistory::create([
            'ch_checkout_id' => $paymongoResponse['checkout_id'],
            'ch_payment_intent_id' => $paymongoResponse['payment_intent_id'] ?? null,
            'ch_mobile_order_id' => $mobileOrderId,
            'ch_customer_id' => (int) $customer->getAuthIdentifier(),
            'ch_customer_name' => $customerData['name'] ?? $customer->c_name,
            'ch_customer_email' => $customerData['email'] ?? $customer->c_email,
            'ch_customer_phone' => $customerData['phone'] ?? $customer->c_phone,
            'ch_customer_address' => $customerData['address'] ?? null,

            'ch_description' => $validated['description'],
            'ch_amount' => (float) $validated['amount'],
            'ch_shipping_fee' => (float) ($orderData['handling_fee'] ?? 0),
            'ch_payment_method' => $validated['payment_method'],
            'ch_status' => 'pending',
            'ch_approval_status' => 'pending_approval',
            'ch_fulfillment_status' => 'pending',

            'ch_product_name' => $orderData['product_name'] ?? null,
            'ch_product_id' => $orderData['product_id'] ?? null,
            'ch_product_sku' => $orderData['product_sku'] ?? null,
            'ch_product_pv' => $orderData['product_pv'] ?? 0,
            'ch_product_image' => $orderData['product_image'] ?? null,
            'ch_quantity' => (int) ($orderData['quantity'] ?? 1),
            'ch_selected_color' => $orderData['selected_color'] ?? null,
            'ch_selected_size' => $orderData['selected_size'] ?? null,
            'ch_selected_type' => $orderData['selected_type'] ?? null,

            'ch_is_mobile' => true,
            'ch_platform' => $validated['platform'],
            'ch_app_version' => $validated['app_version'],
            'ch_device_id' => $validated['device_id'] ?? null,
            'ch_mobile_metadata' => json_encode([
                'platform' => $validated['platform'],
                'app_version' => $validated['app_version'],
                'device_id' => $validated['device_id'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'created_at' => now()->toISOString(),
            ]),
        ]);
    }

    private function createPayMongoCheckoutSession(array $validated, string $mobileOrderId): array
    {
        try {
            $paymongoConfig = $this->getPaymongoConfig($validated['payment_mode'] ?? null);
            
            $secretKey = $paymongoConfig['secret_key'];
            if (!$secretKey) {
                throw new \RuntimeException(sprintf('PayMongo %s secret key is missing.', $paymongoConfig['mode']));
            }

            $payload = [
                'data' => [
                    'attributes' => [
                        'line_items' => [[
                            'currency' => 'PHP',
                            'amount' => (int) round((float) $validated['amount'] * 100),
                            'name' => $validated['description'],
                            'quantity' => 1,
                            'description' => "Mobile Order: {$mobileOrderId}",
                        ]],
                        'payment_method_types' => $this->mapPaymentMethods($validated['payment_method'], $validated['online_banking_provider'] ?? null),
                        'success_url' => config('app.mobile_payment_success_url', 'https://yourapp.com/payment/success'),
                        'cancel_url' => config('app.mobile_payment_cancel_url', 'https://yourapp.com/payment/cancel'),
                        'description' => "Mobile Order: {$mobileOrderId}",
                    ],
                ],
            ];

            $apiUrl = $this->paymongoApiUrl('/v1/checkout_sessions', $paymongoConfig['mode']);

            $response = Http::withBasicAuth($secretKey, '')
                ->post($apiUrl, $payload);

            if ($response->failed()) {
                throw new \RuntimeException('PayMongo create session failed: ' . $response->body());
            }

            $data = $response->json('data');
            
            return [
                'checkout_id' => $data['id'],
                'checkout_url' => $data['attributes']['checkout_url'],
                'payment_intent_id' => $data['attributes']['payment_intent']['id'] ?? null,
                'payment_mode' => $paymongoConfig['mode'],
            ];

        } catch (\Exception $e) {
            throw $e;
        }
    }

    private function mapPaymentMethods(string $method, ?string $onlineBankingProvider = null): array
    {
        return match ($method) {
            'card' => ['card'],
            'gcash' => ['gcash'],
            'maya' => ['paymaya'],
            'online_banking' => [$onlineBankingProvider ?? 'dob'],
            default => ['gcash'],
        };
    }

    private function cacheMobileOrderData(string $mobileOrderId, array $validated, CheckoutHistory $order): void
    {
        Cache::put("mobile_order:{$mobileOrderId}", [
            'validated' => $validated,
            'order_id' => $order->ch_id,
            'checkout_id' => $order->ch_checkout_id,
            'created_at' => now()->toISOString(),
        ], now()->addDays(3));
    }

    private function verifyPayMongoPaymentStatus(string $checkoutId): string
    {
        try {
            $paymentController = new PaymentController();
            $response = Http::withBasicAuth(config('services.paymongo.modes.test.secret_key'), '')
                ->get($paymentController->paymongoApiUrl("/v1/checkout_sessions/{$checkoutId}"));

            if ($response->successful()) {
                $status = $response->json('data.attributes.status');
                return $this->normalizePaymentStatus($status);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to verify PayMongo payment status', [
                'checkout_id' => $checkoutId,
                'error' => $e->getMessage(),
            ]);
        }

        return 'pending';
    }

    private function normalizePaymentStatus(string $status): string
    {
        $paidStatuses = ['paid', 'succeeded', 'success'];
        return in_array(strtolower($status), $paidStatuses, true) ? 'paid' : 'pending';
    }

    private function formatPaymentMethod(string $method): string
    {
        return match ($method) {
            'gcash' => 'GCash',
            'paymaya' => 'Maya',
            'card' => 'Credit/Debit Card',
            'dob', 'ubp' => 'Online Banking',
            default => ucfirst($method),
        };
    }

    private function resolveOrderTrackingNumber(CheckoutHistory $order): ?string
    {
        // Add your tracking number resolution logic here
        return $order->ch_tracking_number ?? null;
    }

    private function getPaymongoConfig(?string $requestedMode = null): array
    {
        $mode = $this->resolveRequestedPaymongoMode($requestedMode);
        $config = (array) config("services.paymongo.modes.{$mode}", []);

        return [
            'mode' => $mode,
            'secret_key' => (string) ($config['secret_key'] ?? ''),
            'public_key' => (string) ($config['public_key'] ?? ''),
            'webhook_secret' => (string) ($config['webhook_secret'] ?? ''),
            'api_base_url' => (string) config('services.paymongo.api_base_url', 'https://api.paymongo.com'),
        ];
    }

    private function resolveRequestedPaymongoMode(?string $requestedMode = null): string
    {
        if ($requestedMode !== null && $requestedMode !== '') {
            if (!in_array($requestedMode, ['test', 'live'], true)) {
                $requestedMode = null;
            }
        }

        if ($requestedMode !== null && $requestedMode !== '') {
            return $requestedMode;
        }

        $defaultMode = config('services.paymongo.default_mode', 'test');
        $allowModeSwitch = config('services.paymongo.allow_mode_switch', false);

        if ($allowModeSwitch && app()->environment(['local', 'development'])) {
            return 'test';
        }

        return $defaultMode;
    }

    private function paymongoApiUrl(string $path, ?string $requestedMode = null): string
    {
        $base = rtrim((string) ($this->getPaymongoConfig($requestedMode)['api_base_url'] ?? 'https://api.paymongo.com'), '/');
        return $base . '/' . ltrim($path, '/');
    }
}
