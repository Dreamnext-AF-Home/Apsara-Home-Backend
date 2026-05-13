<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckoutHistory;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\CustomerVerificationRequest;
use App\Models\EncashmentRequest;
use App\Models\ExpoDeviceToken;
use App\Models\FcmDeviceToken;
use App\Models\OneSignalDeviceToken;
use App\Services\ExpoPushNotificationService;
use App\Services\FirebaseMessagingService;
use App\Services\OneSignalPushNotificationService;
use App\Support\CustomerCashWallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Str;
use Pusher\Pusher;

class CustomerNotificationController extends Controller
{
    public function index(Request $request)
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can access notifications.'], 403);
        }

        $customerId = (int) $customer->c_userid;
        $now = now();

        $pendingOrdersCount = (int) CheckoutHistory::query()
            ->where('ch_customer_id', $customerId)
            ->whereNotIn('ch_fulfillment_status', ['delivered', 'cancelled', 'refunded'])
            ->count();
        $pendingOrdersLatestAt = CheckoutHistory::query()
            ->where('ch_customer_id', $customerId)
            ->whereNotIn('ch_fulfillment_status', ['delivered', 'cancelled', 'refunded'])
            ->max('updated_at');

        $shippingUpdatesCount = (int) CheckoutHistory::query()
            ->where('ch_customer_id', $customerId)
            ->whereIn('ch_fulfillment_status', ['shipped', 'out_for_delivery', 'delivered'])
            ->where('updated_at', '>=', $now->copy()->subDays(7))
            ->count();
        $shippingUpdatesLatestAt = CheckoutHistory::query()
            ->where('ch_customer_id', $customerId)
            ->whereIn('ch_fulfillment_status', ['shipped', 'out_for_delivery', 'delivered'])
            ->where('updated_at', '>=', $now->copy()->subDays(7))
            ->max('updated_at');

        $encashmentUpdatesCount = (int) EncashmentRequest::query()
            ->where('er_customer_id', $customerId)
            ->whereIn('er_status', ['approved_by_admin', 'released', 'rejected', 'failed'])
            ->where('updated_at', '>=', $now->copy()->subDays(14))
            ->count();
        $encashmentUpdatesLatestAt = EncashmentRequest::query()
            ->where('er_customer_id', $customerId)
            ->whereIn('er_status', ['approved_by_admin', 'released', 'rejected', 'failed'])
            ->where('updated_at', '>=', $now->copy()->subDays(14))
            ->max('updated_at');

        $recentReferrals = Customer::query()
            ->where('c_sponsor', $customerId)
            ->where('c_date_started', '>=', $now->copy()->subDays(14))
            ->orderByDesc('c_date_started')
            ->orderByDesc('c_userid')
            ->get([
                'c_userid',
                'c_username',
                'c_fname',
                'c_mname',
                'c_lname',
                'c_date_started',
            ]);

        $recentReferralCount = $recentReferrals->count();
        $recentReferralLatestAt = optional($recentReferrals->first())->c_date_started;
        $recentReferralNames = $recentReferrals
            ->take(3)
            ->map(function (Customer $referral) {
                $name = trim(implode(' ', array_filter([
                    $referral->c_fname ?? null,
                    $referral->c_mname ?? null,
                    $referral->c_lname ?? null,
                ])));

                return $name !== '' ? $name : ((string) ($referral->c_username ?? 'New referral'));
            })
            ->values();

        $kycMeta = $this->resolveKycMeta($customer);
        $kycActionCount = $kycMeta['count'];
        $usernameChangeMeta = $this->resolveUsernameChangeMeta($customer);
        $usernameChangeCount = $usernameChangeMeta['count'];

        $items = [
            [
                'id' => 'orders_pending',
                'title' => 'Orders In Progress',
                'description' => $pendingOrdersCount > 0
                    ? $pendingOrdersCount . ' order(s) are still being processed.'
                    : 'No active order processing right now.',
                'count' => $pendingOrdersCount,
                'severity' => $pendingOrdersCount > 0 ? 'info' : 'success',
                'href' => '/orders',
                'latest_at' => $pendingOrdersLatestAt,
            ],
            [
                'id' => 'shipping_updates',
                'title' => 'Shipping & Delivery Updates',
                'description' => $shippingUpdatesCount > 0
                    ? $shippingUpdatesCount . ' order update(s) were posted this week.'
                    : 'No new shipping updates yet.',
                'count' => $shippingUpdatesCount,
                'severity' => $shippingUpdatesCount > 0 ? 'warning' : 'success',
                'href' => '/orders',
                'latest_at' => $shippingUpdatesLatestAt,
            ],
            [
                'id' => 'encashment_updates',
                'title' => 'Encashment Updates',
                'description' => $encashmentUpdatesCount > 0
                    ? $encashmentUpdatesCount . ' encashment request(s) changed status.'
                    : 'No encashment status changes recently.',
                'count' => $encashmentUpdatesCount,
                'severity' => $encashmentUpdatesCount > 0 ? 'warning' : 'success',
                'href' => '/profile',
                'latest_at' => $encashmentUpdatesLatestAt,
            ],
            [
                'id' => 'referral_registrations',
                'title' => 'Referral Registrations',
                'description' => $recentReferralCount > 0
                    ? $this->buildReferralDescription($recentReferralCount, $recentReferralNames->all())
                    : 'No new referral registrations recently.',
                'count' => $recentReferralCount,
                'severity' => $recentReferralCount > 0 ? 'success' : 'info',
                'href' => '/profile',
                'latest_at' => $recentReferralLatestAt,
            ],
            [
                'id' => 'kyc_status',
                'title' => 'Encashment Verification',
                'description' => $kycMeta['description'],
                'count' => $kycActionCount,
                'severity' => $kycMeta['severity'],
                'href' => '/verification',
                'latest_at' => $kycMeta['latest_at'],
            ],
            [
                'id' => 'username_change_status',
                'title' => 'Username Change Request',
                'description' => $usernameChangeMeta['description'],
                'count' => $usernameChangeCount,
                'severity' => $usernameChangeMeta['severity'],
                'href' => '/profile?tab=change-username',
                'latest_at' => $usernameChangeMeta['latest_at'],
            ],
        ];

        $storedItems = CustomerNotification::query()
            ->where('cn_customer_id', $customerId)
            ->orderByDesc('cn_created_at')
            ->orderByDesc('cn_id')
            ->limit(25)
            ->get()
            ->map(function (CustomerNotification $notification) {
                $item = [
                    'id' => 'customer_notification:' . (int) $notification->cn_id,
                    'title' => (string) ($notification->cn_title ?? 'Account Update'),
                    'description' => (string) ($notification->cn_message ?? ''),
                    'count' => 1,
                    'severity' => (string) ($notification->cn_severity ?? 'info'),
                    'href' => (string) ($notification->cn_href ?? '/profile'),
                    'latest_at' => $notification->cn_created_at
                        ? $notification->cn_created_at->timezone('Asia/Manila')->toIso8601String()
                        : null,
                ];

                // Fetch product image from OrderNotification if this is an order update
                if ($notification->cn_type === 'order_update' && is_array($notification->cn_payload)) {
                    $checkoutId = $notification->cn_payload['checkout_id'] ?? null;
                    if ($checkoutId) {
                        $orderNotification = \App\Models\OrderNotification::query()
                            ->where('on_checkout_id', (string) $checkoutId)
                            ->first();

                        if ($orderNotification && !empty($orderNotification->on_product_image)) {
                            $item['product_image'] = (string) $orderNotification->on_product_image;
                        }
                    }
                }

                return $item;
            })
            ->values()
            ->all();

        $items = collect(array_merge($storedItems, $items))
            ->sortByDesc(function (array $item) {
                return $item['latest_at'] ? strtotime((string) $item['latest_at']) : 0;
            })
            ->values()
            ->all();

        $unreadCount = count($storedItems) + $shippingUpdatesCount + $encashmentUpdatesCount + $recentReferralCount + $kycActionCount + $usernameChangeCount;

        return response()->json([
            'unread_count' => $unreadCount,
            'items' => $items,
            'generated_at' => $now->toDateTimeString(),
        ]);
    }

    private function resolveKycMeta(Customer $customer): array
    {
        $encashmentRules = $this->encashmentRules();
        $availableAmount = CustomerCashWallet::availableForEncashment((int) $customer->c_userid);
        $currentPoints = (float) ($customer->c_gpv ?? 0);
        $hasReachedEncashmentThreshold = $availableAmount >= $encashmentRules['min_amount']
            && $currentPoints >= $encashmentRules['min_points'];
        $status = (int) ($customer->c_accnt_status ?? 0);
        $lock = (int) ($customer->c_lockstatus ?? 0);
        $latestKyc = CustomerVerificationRequest::query()
            ->where('cvr_customer_id', (int) $customer->c_userid)
            ->latest('cvr_id')
            ->first();
        $recentKycWindow = now()->subDays(14);

        if ($lock === 1) {
            return [
                'count' => 1,
                'severity' => 'critical',
                'description' => 'Account is blocked. Please contact support.',
            ];
        }

        if ($latestKyc && (string) $latestKyc->cvr_status === 'approved') {
            $reviewedAt = $latestKyc->cvr_reviewed_at ?? $latestKyc->updated_at ?? $latestKyc->created_at;

            return [
                'count' => ($reviewedAt && $reviewedAt >= $recentKycWindow) ? 1 : 0,
                'severity' => 'success',
                'description' => 'Your KYC verification has been approved. Your affiliate account is now verified.',
                'latest_at' => $reviewedAt?->toDateTimeString(),
            ];
        }

        if ($latestKyc && (string) $latestKyc->cvr_status === 'rejected') {
            $reviewedAt = $latestKyc->cvr_reviewed_at ?? $latestKyc->updated_at ?? $latestKyc->created_at;

            return [
                'count' => ($reviewedAt && $reviewedAt >= $recentKycWindow) ? 1 : 0,
                'severity' => 'critical',
                'description' => 'Your KYC verification was rejected. Please review the requirements and resubmit your documents.',
                'latest_at' => $reviewedAt?->toDateTimeString(),
            ];
        }

        if ($status === 1) {
            return [
                'count' => 0,
                'severity' => 'success',
                'description' => 'Your account is verified.',
                'latest_at' => null,
            ];
        }

        $hasPendingKyc = CustomerVerificationRequest::query()
            ->where('cvr_customer_id', (int) $customer->c_userid)
            ->whereIn('cvr_status', ['pending_review', 'for_review', 'on_hold'])
            ->exists();

        if ($hasPendingKyc || $status === 2) {
            return [
                'count' => 1,
                'severity' => 'warning',
                'description' => 'Your verification request is under review. Please wait for the Admin/KYC update.',
                'latest_at' => optional($latestKyc?->updated_at ?? $latestKyc?->created_at)?->toDateTimeString(),
            ];
        }

        return [
            'count' => $hasReachedEncashmentThreshold ? 1 : 0,
            'severity' => $hasReachedEncashmentThreshold ? 'success' : 'info',
            'description' => $hasReachedEncashmentThreshold
                ? 'You are now qualified to submit your encashment verification. You have reached the minimum encashment requirements.'
                : 'Reach the minimum encashment requirement to unlock verification submission.',
            'latest_at' => $hasReachedEncashmentThreshold ? now()->toDateTimeString() : null,
        ];
    }

    private function encashmentRules(): array
    {
        return [
            'min_amount' => max(1, (float) env('ENCASHMENT_MIN_AMOUNT', 1000)),
            'min_points' => max(0, (float) env('ENCASHMENT_MIN_POINTS', 0)),
        ];
    }

    private function buildReferralDescription(int $count, array $names): string
    {
        if ($count <= 0) {
            return 'No new referral registrations recently.';
        }

        if (empty($names)) {
            return $count . ' new referral registration(s) used your link recently.';
        }

        $preview = implode(', ', array_slice($names, 0, 3));

        if ($count <= 3) {
            return sprintf('%s registered using your referral link.', $preview);
        }

        return sprintf('%s and %d more registered using your referral link.', $preview, $count - 3);
    }

    private function resolveUsernameChangeMeta(Customer $customer): array
    {
        $ticket = DB::table('tbl_tickets')
            ->where('t_subject', 'Username Change Request')
            ->where('t_eid', (int) $customer->c_userid)
            ->orderByDesc('t_id')
            ->first();

        if (!$ticket) {
            return [
                'count' => 0,
                'severity' => 'info',
                'description' => 'No username change requests yet.',
                'latest_at' => null,
            ];
        }

        $decision = DB::table('tbl_tickets_details')
            ->where('t_id', (int) $ticket->t_id)
            ->whereIn('td_replystat', [1, 2])
            ->orderByDesc('td_id')
            ->first();

        if (!$decision) {
            return [
                'count' => 0,
                'severity' => 'warning',
                'description' => 'Your username change request is still under review.',
                'latest_at' => $ticket->t_date ? (string) $ticket->t_date : null,
            ];
        }

        $payload = [];
        if (is_string($decision->td_content ?? null) && trim((string) $decision->td_content) !== '') {
            $decoded = json_decode((string) $decision->td_content, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $reviewedAtRaw = $payload['reviewed_at'] ?? $decision->td_datetime ?? null;
        $reviewedAt = $reviewedAtRaw
            ? \Illuminate\Support\Carbon::parse($reviewedAtRaw)->setTimezone('Asia/Manila')
            : null;

        if ((int) $decision->td_replystat === 2) {
            return [
                'count' => 1,
                'severity' => 'critical',
                'description' => $reviewedAt
                    ? sprintf('Your username request was rejected by admin (%s).', $reviewedAt->format('F j, Y g:i A'))
                    : 'Your username request was rejected by admin.',
                'latest_at' => $reviewedAt?->toDateTimeString(),
            ];
        }

        return [
            'count' => 1,
            'severity' => 'success',
            'description' => $reviewedAt
                ? sprintf('Your username request has been approved by admin (%s).', $reviewedAt->format('F j, Y g:i A'))
                : 'Your username request has been approved by admin.',
            'latest_at' => $reviewedAt?->toDateTimeString(),
        ];
    }

    public function pusherAuth(Request $request)
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can access real-time notifications.'], 403);
        }

        $validated = $request->validate([
            'socket_id' => 'required|string|max:100',
            'channel_name' => 'required|string|max:255',
        ]);

        $channelName = (string) $validated['channel_name'];
        $expectedChannel = 'private-customer-' . (int) $customer->c_userid;

        if ($channelName !== $expectedChannel) {
            return response()->json(['message' => 'Forbidden channel.'], 403);
        }

        $key = (string) config('services.pusher.key', '');
        $secret = (string) config('services.pusher.secret', '');

        if ($key === '' || $secret === '') {
            return response()->json(['message' => 'Pusher is not configured.'], 503);
        }

        $socketId = (string) $validated['socket_id'];
        $signature = hash_hmac('sha256', $socketId . ':' . $channelName, $secret);

        return response()->json([
            'auth' => $key . ':' . $signature,
        ]);
    }

    public function registerExpoToken(Request $request)
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can register devices.'], 403);
        }

        $validated = $request->validate([
            'token' => 'required|string|max:255',
            'device_name' => 'nullable|string|max:255',
            'platform' => 'required|string|in:ios,android,web',
        ]);

        $service = new ExpoPushNotificationService();
        $token = (string) $validated['token'];

        if (!$service->validateToken($token)) {
            return response()->json(['message' => 'Invalid Expo push token format.'], 422);
        }

        $customerId = (int) $customer->c_userid;

        ExpoDeviceToken::updateOrCreate(
            [
                'edt_customer_id' => $customerId,
                'edt_token' => $token,
            ],
            [
                'edt_device_name' => $validated['device_name'] ?? null,
                'edt_platform' => $validated['platform'] ?? 'android',
                'edt_is_active' => true,
                'edt_updated_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Device token registered successfully.',
            'token' => $token,
        ], 201);
    }

    public function unregisterExpoToken(Request $request)
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can unregister devices.'], 403);
        }

        $validated = $request->validate([
            'token' => 'required|string|max:255',
        ]);

        $customerId = (int) $customer->c_userid;
        $token = (string) $validated['token'];

        ExpoDeviceToken::where('edt_customer_id', $customerId)
            ->where('edt_token', $token)
            ->update(['edt_is_active' => false]);

        return response()->json(['message' => 'Device token unregistered successfully.']);
    }

    public function getExpoTokens(Request $request)
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can access device tokens.'], 403);
        }

        $customerId = (int) $customer->c_userid;

        $tokens = ExpoDeviceToken::query()
            ->where('edt_customer_id', $customerId)
            ->where('edt_is_active', true)
            ->get()
            ->map(fn (ExpoDeviceToken $token) => [
                'id' => (int) $token->edt_id,
                'token' => (string) $token->edt_token,
                'device_name' => (string) ($token->edt_device_name ?? 'Unknown Device'),
                'platform' => (string) $token->edt_platform,
                'registered_at' => $token->edt_created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'tokens' => $tokens,
            'count' => $tokens->count(),
        ]);
    }

    public function sendPushNotification(Request $request)
    {
        $user = $request->user();
        if (!$user instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can send notifications.'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:500',
            'data' => 'nullable|array',
            'sound' => 'nullable|string',
            'badge' => 'nullable|integer',
            'priority' => 'nullable|string|in:default,high',
            'channelId' => 'nullable|string|max:255',
        ]);

        $service = new ExpoPushNotificationService();

        $notification = [
            'title' => (string) $validated['title'],
            'body' => (string) $validated['body'],
        ];

        if (!empty($validated['data'])) {
            $notification['data'] = (array) $validated['data'];
        }

        if (isset($validated['sound'])) {
            $notification['sound'] = (string) $validated['sound'];
        }

        if (isset($validated['badge'])) {
            $notification['badge'] = (int) $validated['badge'];
        }

        if (isset($validated['priority'])) {
            $notification['priority'] = (string) $validated['priority'];
        }

        if (isset($validated['channelId'])) {
            $notification['channelId'] = (string) $validated['channelId'];
        }

        $result = $service->sendToCustomer((int) $user->c_userid, $notification);

        return response()->json([
            'message' => 'Push notification queued for delivery.',
            'sent' => $result['sent'],
            'failed' => $result['failed'],
        ], 201);
    }

    public function registerOneSignalToken(Request $request)
    {
        try {
            Log::info('[registerOneSignalToken] Starting device registration', [
                'request_data' => $request->all(),
            ]);

            $customer = $request->user();
            if (!$customer instanceof Customer) {
                Log::warning('[registerOneSignalToken] Unauthorized access attempt', [
                    'user' => $customer?->c_userid,
                ]);
                return response()->json(['message' => 'Only customer accounts can register devices.'], 403);
            }

            Log::info('[registerOneSignalToken] Customer authenticated', [
                'customer_id' => $customer->c_userid,
            ]);

            $validated = $request->validate([
                'player_id' => 'required|string|max:255',
                'device_name' => 'nullable|string|max:255',
                'platform' => 'required|string|in:ios,android,web',
            ]);

            Log::info('[registerOneSignalToken] Validation passed', [
                'validated_data' => $validated,
            ]);

            $service = new OneSignalPushNotificationService();
            $playerId = (string) $validated['player_id'];

            if (!$service->validatePlayerId($playerId)) {
                Log::warning('[registerOneSignalToken] Invalid player ID format', [
                    'player_id' => $playerId,
                ]);
                return response()->json(['message' => 'Invalid OneSignal player ID format.'], 422);
            }

            $customerId = (int) $customer->c_userid;

            Log::info('[registerOneSignalToken] Attempting to save device token', [
                'customer_id' => $customerId,
                'player_id' => $playerId,
                'device_name' => $validated['device_name'] ?? null,
                'platform' => $validated['platform'] ?? 'android',
            ]);

            OneSignalDeviceToken::updateOrCreate(
                [
                    'odt_customer_id' => $customerId,
                    'odt_player_id' => $playerId,
                ],
                [
                    'odt_device_name' => $validated['device_name'] ?? null,
                    'odt_platform' => $validated['platform'] ?? 'android',
                    'odt_is_active' => true,
                    'odt_updated_at' => now(),
                ]
            );

            Log::info('[registerOneSignalToken] Device token saved successfully', [
                'customer_id' => $customerId,
                'player_id' => $playerId,
            ]);

            return response()->json([
                'message' => 'OneSignal device registered successfully.',
                'player_id' => $playerId,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('[registerOneSignalToken] Exception occurred', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to register device. ' . $e->getMessage(),
                'error' => class_basename($e),
            ], 500);
        }
    }

    public function unregisterOneSignalToken(Request $request)
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can unregister devices.'], 403);
        }

        $validated = $request->validate([
            'player_id' => 'required|string|max:255',
        ]);

        $customerId = (int) $customer->c_userid;
        $playerId = (string) $validated['player_id'];

        OneSignalDeviceToken::where('odt_customer_id', $customerId)
            ->where('odt_player_id', $playerId)
            ->update(['odt_is_active' => false]);

        return response()->json(['message' => 'OneSignal device unregistered successfully.']);
    }

    public function getOneSignalTokens(Request $request)
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can access device tokens.'], 403);
        }

        $customerId = (int) $customer->c_userid;

        $tokens = OneSignalDeviceToken::query()
            ->where('odt_customer_id', $customerId)
            ->where('odt_is_active', true)
            ->get()
            ->map(fn (OneSignalDeviceToken $token) => [
                'id' => (int) $token->odt_id,
                'player_id' => (string) $token->odt_player_id,
                'device_name' => (string) ($token->odt_device_name ?? 'Unknown Device'),
                'platform' => (string) $token->odt_platform,
                'registered_at' => $token->odt_created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'tokens' => $tokens,
            'count' => $tokens->count(),
        ]);
    }

    public function sendOneSignalNotification(Request $request)
    {
        $user = $request->user();
        if (!$user instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can send notifications.'], 403);
        }

        $validated = $request->validate([
            'headings' => 'required|string|max:255',
            'contents' => 'required|string|max:500',
            'data' => 'nullable|array',
            'big_picture' => 'nullable|url',
            'ios_attachments' => 'nullable|array',
        ]);

        $service = new OneSignalPushNotificationService();

        $notification = [
            'headings' => ['en' => (string) $validated['headings']],
            'contents' => ['en' => (string) $validated['contents']],
        ];

        if (!empty($validated['data'])) {
            $notification['data'] = (array) $validated['data'];
        }

        if (isset($validated['big_picture'])) {
            $notification['big_picture'] = (string) $validated['big_picture'];
        }

        if (isset($validated['ios_attachments'])) {
            $notification['ios_attachments'] = (array) $validated['ios_attachments'];
        }

        $result = $service->sendToCustomer((int) $user->c_userid, $notification);

        return response()->json([
            'message' => 'OneSignal push notification queued for delivery.',
            'sent' => $result['sent'],
            'failed' => $result['failed'],
        ], 201);
    }

    public function sendTestNotification(Request $request)
    {
        $user = $request->user();
        if (!$user instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can send notifications.'], 403);
        }

        $service = new OneSignalPushNotificationService();

        // Sample notification with image
        $notification = [
            'headings' => ['en' => '🎉 Test Notification'],
            'contents' => ['en' => 'This is a test notification from Apsara Home! Your account is ready.'],
            'data' => [
                'href' => 'purchases://pending/test-order-123',
                'type' => 'test',
            ],
            'big_picture' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=400',
            'ios_attachments' => [
                'image' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=400',
            ],
        ];

        Log::info('📤 Sending test OneSignal notification to customer', [
            'customer_id' => (int) $user->c_userid,
            'notification' => $notification,
        ]);

        $result = $service->sendToCustomer((int) $user->c_userid, $notification);

        return response()->json([
            'message' => '✅ Test notification sent successfully!',
            'notification' => [
                'title' => '🎉 Test Notification',
                'body' => 'This is a test notification from Apsara Home! Your account is ready.',
                'image' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=400',
            ],
            'sent' => $result['sent'],
            'failed' => $result['failed'],
        ], 201);
    }

    public function registerFcmToken(Request $request)
    {
        try {
            Log::info('[registerFcmToken] Starting FCM token registration', [
                'request_data' => $request->all(),
            ]);

            $customer = $request->user();
            if (!$customer instanceof Customer) {
                Log::warning('[registerFcmToken] Unauthorized access attempt');
                return response()->json(['message' => 'Only customer accounts can register devices.'], 403);
            }

            Log::info('[registerFcmToken] Customer authenticated', [
                'customer_id' => $customer->c_userid,
            ]);

            $validated = $request->validate([
                'fcm_token' => 'required|string|max:500',
                'device_name' => 'nullable|string|max:255',
                'platform' => 'required|string|in:ios,android,web',
            ]);

            Log::info('[registerFcmToken] Validation passed', [
                'validated_data' => $validated,
            ]);

            $customerId = (int) $customer->c_userid;

            Log::info('[registerFcmToken] Attempting to save FCM token', [
                'customer_id' => $customerId,
                'fcm_token' => substr($validated['fcm_token'], 0, 20) . '...',
                'platform' => $validated['platform'] ?? 'android',
            ]);

            FcmDeviceToken::updateOrCreate(
                [
                    'fdt_customer_id' => $customerId,
                    'fdt_fcm_token' => $validated['fcm_token'],
                ],
                [
                    'fdt_device_name' => $validated['device_name'] ?? null,
                    'fdt_platform' => $validated['platform'] ?? 'android',
                    'fdt_is_active' => true,
                    'fdt_updated_at' => now(),
                ]
            );

            Log::info('[registerFcmToken] FCM token saved successfully', [
                'customer_id' => $customerId,
            ]);

            return response()->json([
                'message' => 'FCM token registered successfully.',
                'fcm_token' => $validated['fcm_token'],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('[registerFcmToken] Exception occurred', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'stack_trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to register FCM token. ' . $e->getMessage(),
                'error' => class_basename($e),
            ], 500);
        }
    }

    public function sendFcmNotification(Request $request)
    {
        $user = $request->user();
        if (!$user instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can send notifications.'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:500',
            'image' => 'nullable|url',
            'data' => 'nullable|array',
        ]);

        $service = new FirebaseMessagingService();

        $notification = [
            'title' => (string) $validated['title'],
            'body' => (string) $validated['body'],
        ];

        if (isset($validated['image'])) {
            $notification['image'] = (string) $validated['image'];
        }

        if (!empty($validated['data'])) {
            $notification['data'] = (array) $validated['data'];
        }

        $result = $service->sendToCustomer((int) $user->c_userid, $notification);

        return response()->json([
            'message' => 'FCM notification sent successfully.',
            'sent' => $result['sent'],
            'failed' => $result['failed'],
        ], 201);
    }

    public function sendTestFcmNotification(Request $request)
    {
        $user = $request->user();
        if (!$user instanceof Customer) {
            return response()->json(['message' => 'Only customer accounts can send notifications.'], 403);
        }

        $service = new FirebaseMessagingService();

        $notification = [
            'title' => '🎉 Test FCM Notification',
            'body' => 'This is a test notification with image support!',
            'image' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=400',
            'data' => [
                'href' => 'purchases://pending/test-order-123',
                'type' => 'test',
            ],
        ];

        Log::info('📤 Sending test FCM notification to customer', [
            'customer_id' => (int) $user->c_userid,
        ]);

        $result = $service->sendToCustomer((int) $user->c_userid, $notification);

        return response()->json([
            'message' => '✅ Test FCM notification sent successfully!',
            'notification' => [
                'title' => '🎉 Test FCM Notification',
                'body' => 'This is a test notification with image support!',
                'image' => 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=400',
            ],
            'sent' => $result['sent'],
            'failed' => $result['failed'],
        ], 201);
    }
}
