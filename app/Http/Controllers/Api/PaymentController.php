<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminNotification;
use App\Mail\Checkout\CheckoutCompletedMail;
use App\Models\CheckoutHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Pusher\Pusher;


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

    private function paymongoApiUrl(string $path): string
    {
        $base = rtrim((string) config('services.paymongo.api_base_url', 'https://api.paymongo.com'), '/');
        return $base . '/' . ltrim($path, '/');
    }

    public function createCheckoutSession(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1',
            'description' => 'required|string|max:255',
            'payment_method' => 'required|in:online_banking,card,gcash,maya',
            'voucher_code' => 'nullable|string|max:80',

            'customer' => 'nullable|array',
            'customer.name' => 'nullable|string|max:255',
            'customer.email' => 'nullable|email|max:255',
            'customer.phone' => 'nullable|string|max:50',
            'customer.address' => 'nullable|string|max:500',
            'customer.referred_by' => 'nullable|string|max:255',
            'customer.is_member' => 'nullable|boolean',
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

        $isMember = (bool) data_get($validated, 'customer.is_member', false);
        $customerId = auth('sanctum')->id();
        $requiresReferral = !$customerId;
        $normalizedReferral = $this->normalizeReferralValue((string) data_get($validated, 'customer.referred_by', ''));
        $referrer = null;
        $referralSourceType = null;
        $authenticatedCustomer = $customerId
            ? \App\Models\Customer::query()
                ->select(['c_userid', 'c_sponsor'])
                ->with('sponsor:c_userid,c_username,c_acct_status,c_lockstatus')
                ->find((int) $customerId)
            : null;

        if ($authenticatedCustomer instanceof \App\Models\Customer) {
            $sponsorUsername = trim((string) ($authenticatedCustomer->sponsor?->c_username ?? ''));
            if ($normalizedReferral === '' && $sponsorUsername !== '') {
                $normalizedReferral = $sponsorUsername;
                $referrer = $authenticatedCustomer->sponsor;
                $referralSourceType = 'member_sponsor';
            }
        }

        if ($normalizedReferral === '' && $requiresReferral && !$isMember) {
            return response()->json([
                'message' => 'The referred by field is required.',
                'errors' => [
                    'customer.referred_by' => ['The referred by field is required.'],
                ],
            ], 422);
        }

        if ($normalizedReferral !== '') {
            if (!$referrer) {
                $referrer = $this->resolveValidReferrer($normalizedReferral);
            }
            if (!$referrer) {
                return response()->json([
                    'message' => 'Referral code is invalid or referrer account is not verified.',
                    'errors' => [
                        'customer.referred_by' => ['Referral code is invalid or referrer account is not verified.'],
                    ],
                ], 422);
            }
            if ($referralSourceType === null) {
                $referralSourceType = 'checkout_referral';
            }
        }

        $secretKey = config('services.paymongo.secret_key');
        if (!$secretKey) {
            return response()->json(['message' => 'PAYMONGO_SECRET_KEY missing'], 500);
        }

        $frontend = env('FRONTEND_URL', 'http://localhost:3000');

        $voucherCode = trim((string) ($validated['voucher_code'] ?? ''));
        $subtotal = (float) ($validated['order']['subtotal'] ?? $validated['amount'] ?? 0);
        $handlingFee = (float) ($validated['order']['handling_fee'] ?? 0);

        $voucher = null;
        $voucherDiscount = 0.0;
        if ($voucherCode !== '') {
            $voucher = $this->resolveAffiliateVoucher($voucherCode);
            if (!$voucher) {
                return response()->json(['message' => 'Voucher code is invalid or expired.'], 422);
            }

            $voucherDiscount = min($subtotal, (float) ($voucher->avi_amount ?? 0));
        }

        $computedAmount = max(0, $subtotal - $voucherDiscount) + $handlingFee;

        $payload = [
            'data' => [
                'attributes' => [
                    'line_items' => [[
                        'currency' => 'PHP',
                        'amount' => (int) round($computedAmount * 100), // centavos
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
            ->post($this->paymongoApiUrl('/v1/checkout_sessions'), $payload);

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
                'referred_by' => $normalizedReferral,
                'referrer_user_id' => $referrer ? (int) $referrer->c_userid : null,
                'referral_source_type' => $referralSourceType,
                'description' => $validated['description'],
                'amount' => (float) $computedAmount,
                'payment_method' => $validated['payment_method'],
                'order' => $validated['order'] ?? [],
                'voucher' => $voucher ? [
                    'id' => (int) $voucher->avi_id,
                    'code' => (string) ($voucher->avi_code ?? ''),
                    'amount' => (float) ($voucher->avi_amount ?? 0),
                    'discount' => (float) $voucherDiscount,
                ] : null,
            ], now()->addDays(3));

            Log::info('Checkout cached for email confirmation', [
                'checkout_id' => $checkoutId,
                'customer_email' => $validated['customer']['email'] ?? null,
                'payment_method' => $validated['payment_method'],
            ]);
        }

        return response()->json([
            'checkout_id' => $checkoutId,
            'checkout_url' => $data['attributes']['checkout_url'] ?? null,
        ]);
    }

    public function validateVoucher(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:80',
            'subtotal' => 'nullable|numeric|min:0',
        ]);

        $voucher = $this->resolveAffiliateVoucher((string) $validated['code']);
        if (!$voucher) {
            return response()->json(['message' => 'Voucher code is invalid or expired.'], 422);
        }

        $subtotal = (float) ($validated['subtotal'] ?? ($voucher->avi_amount ?? 0));
        $discount = min($subtotal, (float) ($voucher->avi_amount ?? 0));

        return response()->json([
            'valid' => true,
            'voucher' => [
                'id' => (int) $voucher->avi_id,
                'code' => (string) ($voucher->avi_code ?? ''),
                'amount' => (float) ($voucher->avi_amount ?? 0),
                'max_uses' => $voucher->avi_max_uses !== null ? (int) $voucher->avi_max_uses : null,
                'used_count' => $voucher->avi_used_count !== null ? (int) $voucher->avi_used_count : null,
                'expires_at' => $voucher->avi_expires_at,
            ],
            'discount' => round($discount, 2),
        ]);
    }

    public function verifyCheckoutSession(Request $request, string $checkoutId)
    {
        $secretKey = config('services.paymongo.secret_key');
        if (!$secretKey) {
            return response()->json(['message' => 'PAYMONGO_SECRET_KEY missing'], 500);
        }

        $res = Http::withBasicAuth($secretKey, '')
            ->get($this->paymongoApiUrl("/v1/checkout_sessions/{$checkoutId}"));

        if ($res->failed()) {
            return response()->json([
                'message' => 'PayMongo verify failed',
                'error' => $res->json(),
            ], $res->status());
        }

        $attrs = $res->json('data.attributes');
        $status = $this->resolveCheckoutStatusForStorage(is_array($attrs) ? $attrs : []);
        $attrs['status'] = $status;

        Log::info('Checkout verify response received', [
            'checkout_id' => $checkoutId,
            'status' => $status,
            'has_cached_customer' => Cache::has("checkout_customer:{$checkoutId}"),
        ]);

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

    public function handlePaymongoWebhook(Request $request)
    {
        $payload = $request->json()->all();
        $rawBody = $request->getContent();
        $signatureHeader = (string) $request->header('Paymongo-Signature', '');

        if (!$this->isValidPaymongoWebhookSignature($rawBody, $signatureHeader)) {
            Log::warning('PayMongo webhook rejected: invalid signature.', [
                'has_signature' => $signatureHeader !== '',
            ]);
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $eventType = strtolower((string) data_get($payload, 'data.attributes.type', ''));
        $paidEventTypes = ['checkout_session.payment.paid', 'checkout_session.paid', 'payment.paid'];

        if (!in_array($eventType, $paidEventTypes, true)) {
            Log::info('PayMongo webhook ignored: unsupported event type.', [
                'event_type' => $eventType ?: 'unknown',
            ]);
            return response()->json([
                'received' => true,
                'processed' => false,
                'reason' => 'unsupported_event',
                'event_type' => $eventType ?: null,
            ]);
        }

        $checkoutId = $this->extractCheckoutIdFromWebhook($payload);
        if (!$checkoutId) {
            Log::warning('PayMongo webhook ignored: checkout id missing.', ['event_type' => $eventType]);
            return response()->json([
                'received' => true,
                'processed' => false,
                'reason' => 'missing_checkout_id',
            ], 202);
        }

        $attrs = $this->extractCheckoutAttributesFromWebhook($payload);
        $attrs['status'] = $attrs['status'] ?? 'paid';
        $attrs = $this->hydrateCheckoutAttributesIfNeeded($checkoutId, $attrs);

        $this->persistCheckoutHistoryIfNeeded($checkoutId, $attrs);
        if ($this->isPaidStatus($attrs['status'] ?? 'paid')) {
            $this->sendCheckoutCompletedEmailIfNeeded($checkoutId, $attrs);
        }

        Log::info('PayMongo webhook processed.', [
            'event_type' => $eventType,
            'checkout_id' => $checkoutId,
            'status' => $attrs['status'] ?? null,
        ]);

        return response()->json([
            'received' => true,
            'processed' => true,
            'checkout_id' => $checkoutId,
            'status' => $attrs['status'] ?? null,
            'payment_intent_id' => data_get($attrs, 'payment_intent.id'),
        ]);
    }

    public function handleTestPaidWebhook(Request $request)
    {
        if (!app()->environment('local') && !app()->environment('development')) {
            return response()->json(['message' => 'Test webhook is only allowed in non-production environments.'], 403);
        }

        $validated = $request->validate([
            'checkout_id' => 'required|string|max:255',
            'status' => 'nullable|string|max:50',
            'payment_intent_id' => 'nullable|string|max:255',
        ]);

        $checkoutId = (string) $validated['checkout_id'];
        $attrs = [
            'status' => (string) ($validated['status'] ?? 'paid'),
            'payment_intent' => [
                'id' => $validated['payment_intent_id'] ?? null,
            ],
        ];
        $attrs = $this->hydrateCheckoutAttributesIfNeeded($checkoutId, $attrs);

        $this->persistCheckoutHistoryIfNeeded($checkoutId, $attrs);
        if ($this->isPaidStatus($attrs['status'] ?? 'paid')) {
            $this->sendCheckoutCompletedEmailIfNeeded($checkoutId, $attrs);
        }

        return response()->json([
            'received' => true,
            'processed' => true,
            'mode' => 'test',
            'checkout_id' => $checkoutId,
            'status' => $attrs['status'] ?? null,
            'payment_intent_id' => data_get($attrs, 'payment_intent.id'),
        ]);
    }

    private function sendCheckoutCompletedEmailIfNeeded(string $checkoutId, array $attrs): void
    {
        $customer = Cache::get("checkout_customer:{$checkoutId}");
        if (!$customer || empty($customer['email'])) {
            Log::warning('Checkout email skipped: missing cached customer/email', [
                'checkout_id' => $checkoutId,
                'has_customer' => (bool) $customer,
            ]);
            return;
        }
        $recipient = env('MAIL_TEST_TO') ?: $customer['email'];

        $notifiedKey = "checkout_email_sent:{$checkoutId}";
        if (!Cache::add($notifiedKey, true, now()->addDays(7))) {
            Log::info('Checkout email skipped: already sent', ['checkout_id' => $checkoutId]);
            return;
        }

        try {
            $order = is_array($customer['order'] ?? null) ? $customer['order'] : [];

            Mail::mailer('resend')->to($recipient)->send(new CheckoutCompletedMail([
                'checkout_id' => $checkoutId,
                'customer_name' => $customer['name'] ?? 'Customer',
                'description' => $customer['description'] ?? 'Order',
                'amount' => $customer['amount'] ?? 0,
                'payment_method' => $customer['payment_method'] ?? null,
                'status' => $attrs['status'] ?? 'paid',
                'payment_intent_id' => $attrs['payment_intent']['id'] ?? null,
                'shipping_address' => $customer['address'] ?? null,
                'order' => [
                    'product_name' => $order['product_name'] ?? null,
                    'product_sku' => $order['product_sku'] ?? null,
                    'quantity' => $order['quantity'] ?? 1,
                    'selected_color' => $order['selected_color'] ?? null,
                    'selected_size' => $order['selected_size'] ?? null,
                    'selected_type' => $order['selected_type'] ?? null,
                ],
            ]));

            Log::info('Checkout email sent', [
                'checkout_id' => $checkoutId,
                'recipient' => $recipient,
                'mailer' => 'resend',
            ]);
        } catch (\Throwable $e) {
            Cache::forget($notifiedKey);
            Log::error('Checkout email send failed', [
                'checkout_id' => $checkoutId,
                'recipient' => $recipient,
                'error' => $e->getMessage(),
            ]);
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
                        'selected_color' => $order->ch_selected_color ?: null,
                        'selected_size' => $order->ch_selected_size ?: null,
                        'selected_type' => $order->ch_selected_type ?: null,
                    ]],
                    'total' => (float) $order->ch_amount,
                    'shipping_fee' => 0,
                    'payment_method' => $this->formatPaymentMethod((string) $order->ch_payment_method),
                    'shipping_address' => $order->ch_customer_address ?: 'No address provided',
                    'courier' => $order->ch_courier ?: null,
                    'tracking_no' => $order->ch_tracking_no ?: null,
                    'shipment_status' => $order->ch_shipment_status ?: null,
                    'shipped_at' => optional($order->ch_shipped_at)->toDateTimeString(),
                    'created_at' => optional($order->ch_paid_at ?? $order->created_at)->toDateTimeString(),
                    'estimated_delivery' => null,
                ];
            })
            ->values();

        return response()->json([
            'orders' => $orders,
        ]);
    }

    public function trackGuestOrder(Request $request)
    {
        $validated = $request->validate([
            'order_number' => 'required|string|max:255',
            'contact' => 'required|string|max:255',
        ]);

        $orderNumber = trim((string) $validated['order_number']);
        $contact = trim((string) $validated['contact']);

        $order = CheckoutHistory::query()
            ->where('ch_checkout_id', $orderNumber)
            ->orderByDesc('ch_id')
            ->first();

        if (!$order) {
            return response()->json(['message' => 'No order found for the provided reference.'], 404);
        }

        $normalizedContact = $this->normalizeContact($contact);
        $storedEmail = $this->normalizeContact((string) ($order->ch_customer_email ?? ''));
        $storedPhone = $this->normalizeContact((string) ($order->ch_customer_phone ?? ''));

        if ($normalizedContact === '' || ($normalizedContact !== $storedEmail && $normalizedContact !== $storedPhone)) {
            return response()->json(['message' => 'The order reference and contact details do not match.'], 404);
        }

        return response()->json([
            'order' => $this->transformCheckoutHistoryOrder($order, true),
        ]);
    }

    private function persistCheckoutHistoryIfNeeded(string $checkoutId, array $attrs): void
    {
        $normalizedIncomingStatus = $this->resolveCheckoutStatusForStorage($attrs);
        $attrs['status'] = $normalizedIncomingStatus;

        $cached = Cache::get("checkout_customer:{$checkoutId}");
        if (!$cached) {
            $history = CheckoutHistory::query()
                ->where('ch_checkout_id', $checkoutId)
                ->first();

            if (!$history) {
                return;
            }

            $wasPaidBefore = $this->isPaidStatus($history->ch_status ?? null);
            $isNowPaid = $this->isPaidStatus($attrs['status'] ?? null);

            $history->ch_status = (string) ($attrs['status'] ?? $history->ch_status ?? 'pending');
            $history->ch_payment_intent_id = data_get($attrs, 'payment_intent.id') ?: $history->ch_payment_intent_id;
            if ($isNowPaid && !$history->ch_paid_at) {
                $history->ch_paid_at = now();
            }
            $history->save();

            if ($isNowPaid && !$wasPaidBefore) {
                $this->notifyAdminOrderCreated($history);
            }

            return;
        }

        $order = is_array($cached['order'] ?? null) ? $cached['order'] : [];
        $quantity = (int) ($order['quantity'] ?? 1);
        $quantity = $quantity > 0 ? $quantity : 1;
        $alreadyExists = CheckoutHistory::query()
            ->where('ch_checkout_id', $checkoutId)
            ->exists();
        $existingPaymentStatus = CheckoutHistory::query()
            ->where('ch_checkout_id', $checkoutId)
            ->value('ch_status');
        $existingFulfillmentStatus = CheckoutHistory::query()
            ->where('ch_checkout_id', $checkoutId)
            ->value('ch_fulfillment_status');
        $existingApprovalStatus = CheckoutHistory::query()
            ->where('ch_checkout_id', $checkoutId)
            ->value('ch_approval_status');

        $history = CheckoutHistory::updateOrCreate(
            ['ch_checkout_id' => $checkoutId],
            [
                // Guest checkouts do not have a member account, so store 0 as a sentinel.
                'ch_customer_id' => (int) ($cached['customer_id'] ?? 0),
                'ch_payment_intent_id' => data_get($attrs, 'payment_intent.id'),
                'ch_status' => (string) ($attrs['status'] ?? 'paid'),
                'ch_description' => (string) ($cached['description'] ?? ''),
                'ch_amount' => (float) ($cached['amount'] ?? 0),
                'ch_payment_method' => (string) ($cached['payment_method'] ?? ''),
                'ch_quantity' => $quantity,
                'ch_product_name' => (string) ($order['product_name'] ?? ($cached['description'] ?? 'Order Item')),
                'ch_product_id' => isset($order['product_id']) ? (int) $order['product_id'] : null,
                'ch_product_sku' => (string) ($order['product_sku'] ?? ''),
                'ch_product_pv' => isset($order['product_pv']) ? (float) $order['product_pv'] : 0,
                'ch_earned_pv' => isset($order['product_pv']) ? ((float) $order['product_pv'] * $quantity) : 0,
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

        $isNowPaid = $this->isPaidStatus($attrs['status'] ?? null);
        $wasPaidBefore = $this->isPaidStatus($existingPaymentStatus);

        if ($isNowPaid && (!$alreadyExists || !$wasPaidBefore)) {
            $this->notifyAdminOrderCreated($history);
        }

        if ($isNowPaid) {
            $this->markAffiliateVoucherUsedIfNeeded($checkoutId, is_array($cached) ? $cached : []);
        }
    }

    private function resolveAffiliateVoucher(string $code): ?object
    {
        if (!Schema::hasTable('tbl_affiliate_voucher_issuances')) {
            return null;
        }

        $normalized = mb_strtolower(trim($code), 'UTF-8');
        if ($normalized === '') {
            return null;
        }

        $voucher = DB::table('tbl_affiliate_voucher_issuances')
            ->whereRaw('LOWER(avi_code) = ?', [$normalized])
            ->first();

        if (!$voucher || (string) ($voucher->avi_status ?? '') !== 'active') {
            return null;
        }

        if (!empty($voucher->avi_expires_at)) {
            $expiresAt = \Illuminate\Support\Carbon::parse($voucher->avi_expires_at, 'Asia/Manila');
            if ($expiresAt->isPast()) {
                return null;
            }
        }

        $maxUses = $voucher->avi_max_uses !== null ? (int) $voucher->avi_max_uses : null;
        $usedCount = (int) ($voucher->avi_used_count ?? 0);
        if ($maxUses !== null && $usedCount >= $maxUses) {
            return null;
        }

        return $voucher;
    }

    private function markAffiliateVoucherUsedIfNeeded(string $checkoutId, array $cached): void
    {
        $voucher = $cached['voucher'] ?? null;
        if (!is_array($voucher) || empty($voucher['id'])) {
            return;
        }

        $cacheKey = "checkout_voucher_applied:{$checkoutId}";
        if (Cache::has($cacheKey)) {
            return;
        }

        DB::transaction(function () use ($voucher, $cached) {
            if (!Schema::hasTable('tbl_affiliate_voucher_issuances')) {
                return;
            }

            $row = DB::table('tbl_affiliate_voucher_issuances')
                ->where('avi_id', (int) $voucher['id'])
                ->lockForUpdate()
                ->first();

            if (!$row || (string) ($row->avi_status ?? '') !== 'active') {
                return;
            }

            if (!empty($row->avi_expires_at)) {
                $expiresAt = \Illuminate\Support\Carbon::parse($row->avi_expires_at, 'Asia/Manila');
                if ($expiresAt->isPast()) {
                    return;
                }
            }

            $maxUses = $row->avi_max_uses !== null ? (int) $row->avi_max_uses : null;
            $usedCount = (int) ($row->avi_used_count ?? 0);
            if ($maxUses !== null && $usedCount >= $maxUses) {
                return;
            }

            $nextUsed = $usedCount + 1;

            $update = [
                'avi_used_count' => $nextUsed,
                'avi_redeemed_at' => now('Asia/Manila'),
            ];

            if (!empty($cached['customer_id'])) {
                $update['avi_redeemed_by_customer_id'] = (int) $cached['customer_id'];
            }

            if ($maxUses !== null && $nextUsed >= $maxUses) {
                $update['avi_status'] = 'redeemed';
            }

            DB::table('tbl_affiliate_voucher_issuances')
                ->where('avi_id', (int) $row->avi_id)
                ->update($update);
        });

        Cache::put($cacheKey, true, now()->addDays(7));
    }

    private function notifyAdminOrderCreated(CheckoutHistory $history): void
    {
        $customerName = trim((string) ($history->ch_customer_name ?? 'Customer'));
        $checkoutId = (string) ($history->ch_checkout_id ?? '');
        $amount = (float) ($history->ch_amount ?? 0);

        $notification = AdminNotification::query()->firstOrCreate(
            [
                'an_type' => 'order_created',
                'an_source_type' => 'order',
                'an_source_id' => (int) $history->ch_id,
            ],
            [
                'an_severity' => 'warning',
                'an_title' => 'New Order Placed',
                'an_message' => sprintf(
                    '%s placed order %s (%s).',
                    $customerName !== '' ? $customerName : 'Customer',
                    $checkoutId !== '' ? $checkoutId : '#' . (int) $history->ch_id,
                    'PHP ' . number_format($amount, 2)
                ),
                'an_href' => '/admin/orders/pending',
                'an_payload' => [
                    'order_id' => (int) $history->ch_id,
                    'checkout_id' => $checkoutId,
                    'customer_name' => $customerName,
                    'amount' => $amount,
                ],
                'an_created_at' => now(),
            ]
        );

        $appId = (string) config('services.pusher.app_id', '');
        $key = (string) config('services.pusher.key', '');
        $secret = (string) config('services.pusher.secret', '');

        if ($appId === '' || $key === '' || $secret === '') {
            return;
        }

        $cluster = (string) config('services.pusher.cluster', 'ap1');
        $useTls = (bool) config('services.pusher.use_tls', true);

        try {
            $pusher = new Pusher(
                $key,
                $secret,
                $appId,
                [
                    'cluster' => $cluster,
                    'useTLS' => $useTls,
                ]
            );

            $pusher->trigger('private-admin-orders', 'order.created', [
                'order_id' => (int) $history->ch_id,
                'checkout_id' => (string) $history->ch_checkout_id,
                'notification_id' => (int) $notification->an_id,
                'type' => 'order_created',
                'title' => (string) $notification->an_title,
                'description' => (string) $notification->an_message,
                'created_at' => now()->toDateTimeString(),
            ]);
            $pusher->trigger('private-admin-orders', 'notification.created', [
                'id' => (int) $notification->an_id,
                'type' => 'order_created',
                'title' => (string) $notification->an_title,
                'description' => (string) $notification->an_message,
                'href' => (string) ($notification->an_href ?? '/admin/orders/pending'),
                'created_at' => now()->toDateTimeString(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to publish admin realtime order notification.', [
                'checkout_id' => (string) $history->ch_checkout_id,
                'error' => $e->getMessage(),
            ]);
        }
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

    private function extractCheckoutIdFromWebhook(array $payload): ?string
    {
        $candidates = [
            data_get($payload, 'data.attributes.data.id'),
            data_get($payload, 'data.attributes.data.attributes.id'),
            data_get($payload, 'data.attributes.data.attributes.checkout_session_id'),
            data_get($payload, 'data.attributes.checkout_session_id'),
            data_get($payload, 'data.id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function extractCheckoutAttributesFromWebhook(array $payload): array
    {
        $attrs = data_get($payload, 'data.attributes.data.attributes');
        return is_array($attrs) ? $attrs : [];
    }

    private function hydrateCheckoutAttributesIfNeeded(string $checkoutId, array $attrs): array
    {
        $hasStatus = !empty($attrs['status']);
        $hasPaymentIntent = !empty(data_get($attrs, 'payment_intent.id'));
        if ($hasStatus && $hasPaymentIntent) {
            return $attrs;
        }

        $secretKey = config('services.paymongo.secret_key');
        if (!$secretKey) {
            return $attrs;
        }

        try {
            $res = Http::withBasicAuth($secretKey, '')
                ->get($this->paymongoApiUrl("/v1/checkout_sessions/{$checkoutId}"));

            if ($res->failed()) {
                Log::warning('Failed to hydrate checkout attributes from PayMongo API.', [
                    'checkout_id' => $checkoutId,
                    'status' => $res->status(),
                ]);
                return $attrs;
            }

            $fetched = $res->json('data.attributes');
            if (!is_array($fetched)) {
                return $attrs;
            }

            return array_merge($fetched, $attrs);
        } catch (\Throwable $e) {
            Log::warning('Error hydrating checkout attributes from PayMongo API.', [
                'checkout_id' => $checkoutId,
                'error' => $e->getMessage(),
            ]);
            return $attrs;
        }
    }

    private function isValidPaymongoWebhookSignature(string $rawBody, string $header): bool
    {
        $secret = (string) config('services.paymongo.webhook_secret', '');
        if ($secret === '') {
            Log::warning('PAYMONGO_WEBHOOK_SECRET is not configured. Skipping signature verification (testing mode).');
            return true;
        }

        if ($header === '' || $rawBody === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $segment) {
            [$key, $value] = array_pad(explode('=', trim($segment), 2), 2, null);
            if ($key && $value) {
                $parts[trim($key)] = trim($value);
            }
        }

        $timestamp = $parts['t'] ?? null;
        if (!$timestamp) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
        $signatures = array_filter([
            $parts['te'] ?? null,
            $parts['li'] ?? null,
            $parts['v1'] ?? null,
            $parts['s'] ?? null,
        ]);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function transformCheckoutHistoryOrder(CheckoutHistory $order, bool $includeGuestFields = false): array
    {
        $quantity = max(1, (int) $order->ch_quantity);
        $itemName = $order->ch_product_name ?: ($order->ch_description ?: 'Order Item');
        $status = $order->ch_fulfillment_status
            ? (string) $order->ch_fulfillment_status
            : $this->mapCheckoutStatusToOrderStatus((string) $order->ch_status);

        $payload = [
            'id' => (int) $order->ch_id,
            'order_number' => $order->ch_checkout_id,
            'status' => $status,
            'items' => [[
                'id' => (int) $order->ch_id,
                'name' => $itemName,
                'image' => $order->ch_product_image ?: '/Images/HeroSection/sofas.jpg',
                'quantity' => $quantity,
                'price' => $quantity > 0 ? ((float) $order->ch_amount / $quantity) : (float) $order->ch_amount,
                'selected_color' => $order->ch_selected_color ?: null,
                'selected_size' => $order->ch_selected_size ?: null,
                'selected_type' => $order->ch_selected_type ?: null,
            ]],
            'total' => (float) $order->ch_amount,
            'shipping_fee' => 0,
            'payment_method' => $this->formatPaymentMethod((string) $order->ch_payment_method),
            'shipping_address' => $order->ch_customer_address ?: 'No address provided',
            'created_at' => optional($order->ch_paid_at ?? $order->created_at)->toDateTimeString(),
            'estimated_delivery' => null,
        ];

        if ($includeGuestFields) {
            $payload['customer_name'] = (string) ($order->ch_customer_name ?? 'Customer');
            $payload['courier'] = $order->ch_courier ?: null;
            $payload['tracking_no'] = $order->ch_tracking_no ?: null;
            $payload['shipment_status'] = $order->ch_shipment_status ?: null;
            $payload['shipped_at'] = optional($order->ch_shipped_at)->toDateTimeString();
        }

        return $payload;
    }

    private function normalizeContact(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            return strtolower($trimmed);
        }

        return preg_replace('/\D+/', '', $trimmed) ?? '';
    }

    private function normalizeReferralValue(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        if (filter_var($trimmed, FILTER_VALIDATE_URL)) {
            $parts = parse_url($trimmed);
            parse_str($parts['query'] ?? '', $query);

            $fromQuery = trim((string) ($query['ref'] ?? $query['referred_by'] ?? ''));
            if ($fromQuery !== '') {
                return $fromQuery;
            }

            $path = trim((string) ($parts['path'] ?? ''), '/');
            if ($path !== '') {
                $segments = explode('/', $path);
                return trim((string) end($segments));
            }
        }

        return $trimmed;
    }

    private function resolveValidReferrer(string $referral): ?\App\Models\Customer
    {
        return \App\Models\Customer::query()
            ->select(['c_userid', 'c_username', 'c_accnt_status', 'c_lockstatus'])
            ->whereRaw('LOWER(c_username) = ?', [strtolower($referral)])
            ->where('c_accnt_status', 1)
            ->where('c_lockstatus', 0)
            ->first();
    }
}
