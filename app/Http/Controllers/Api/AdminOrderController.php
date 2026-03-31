<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\Checkout\OrderStatusUpdatedMail;
use App\Models\Admin;
use App\Models\AdminNotification;
use App\Models\AdminNotificationRead;
use App\Models\CheckoutHistory;
use App\Models\Customer;
use App\Models\CustomerWalletLedger;
use App\Services\Shipping\JntShippingService;
use App\Services\Shipping\XdeShippingService;
use App\Support\AdminAccess;
use App\Support\DirectAffiliatePerformanceBonus;
use App\Support\DirectReferralCommission;
use App\Support\GroupPurchaseBonus;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

class AdminOrderController extends Controller
{
    public function __construct(
        private readonly XdeShippingService $xdeShippingService,
        private readonly JntShippingService $jntShippingService
    )
    {
    }

    public function notifications(Request $request)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $this->backfillOrderNotificationsIfEmpty();

        $limit = max(10, min(50, (int) $request->query('limit', 20)));
        $rows = AdminNotification::query()
            ->orderByDesc('an_created_at')
            ->orderByDesc('an_id')
            ->limit(max(100, $limit * 5))
            ->get();

        $rows = $rows
            ->filter(fn (AdminNotification $notification) => $this->canAdminSeeNotification($admin, $notification))
            ->take($limit)
            ->values();

        $notificationIds = $rows->pluck('an_id')->map(fn ($id) => (int) $id)->all();
        $readIds = [];
        if (!empty($notificationIds)) {
            $readIds = AdminNotificationRead::query()
                ->where('anr_admin_id', (int) $admin->id)
                ->whereIn('anr_notification_id', $notificationIds)
                ->pluck('anr_notification_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }
        $readLookup = array_fill_keys($readIds, true);

        $items = $rows->map(function (AdminNotification $row) use ($readLookup) {
            $id = (int) $row->an_id;
            $isRead = isset($readLookup[$id]);

            return [
                'id' => (string) $id,
                'type' => (string) ($row->an_type ?? 'system'),
                'title' => (string) $row->an_title,
                'description' => (string) ($row->an_message ?? ''),
                'severity' => (string) ($row->an_severity ?? 'info'),
                'href' => (string) ($row->an_href ?? '/admin/orders'),
                'count' => $isRead ? 0 : 1,
                'is_read' => $isRead,
                'updated_at' => optional($row->an_created_at)->toDateTimeString(),
                'payload' => is_array($row->an_payload) ? $row->an_payload : null,
            ];
        })->values()->all();

        usort($items, function (array $left, array $right) {
            if (($left['is_read'] ?? false) !== ($right['is_read'] ?? false)) {
                return ($left['is_read'] ?? false) <=> ($right['is_read'] ?? false);
            }

            return strcmp((string) ($right['updated_at'] ?? ''), (string) ($left['updated_at'] ?? ''));
        });

        $unreadCount = collect($items)->where('is_read', false)->count();

        return response()->json([
            'unread_count' => $unreadCount,
            'items' => $items,
            'generated_at' => now()->toDateTimeString(),
        ]);
    }

    public function markNotificationRead(Request $request, int $id)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $notification = AdminNotification::query()->where('an_id', $id)->first();
        if (!$notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }
        if (! $this->canAdminSeeNotification($admin, $notification)) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        AdminNotificationRead::query()->updateOrCreate(
            [
                'anr_notification_id' => (int) $notification->an_id,
                'anr_admin_id' => (int) $admin->id,
            ],
            [
                'anr_read_at' => now(),
            ]
        );

        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function markAllNotificationsRead(Request $request)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $ids = AdminNotification::query()
            ->orderByDesc('an_created_at')
            ->orderByDesc('an_id')
            ->limit(200)
            ->get()
            ->filter(fn (AdminNotification $notification) => $this->canAdminSeeNotification($admin, $notification))
            ->pluck('an_id')
            ->map(fn ($value) => (int) $value)
            ->all();

        if (empty($ids)) {
            return response()->json(['message' => 'No notifications to mark as read.']);
        }

        $now = now();
        $rows = array_map(
            fn (int $notificationId) => [
                'anr_notification_id' => $notificationId,
                'anr_admin_id' => (int) $admin->id,
                'anr_read_at' => $now,
            ],
            $ids
        );

        DB::table('tbl_admin_notification_reads')->upsert(
            $rows,
            ['anr_notification_id', 'anr_admin_id'],
            ['anr_read_at']
        );

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    private function backfillOrderNotificationsIfEmpty(): void
    {
        if (AdminNotification::query()->exists()) {
            return;
        }

        $orders = CheckoutHistory::query()
            ->orderByDesc('ch_paid_at')
            ->orderByDesc('ch_id')
            ->limit(100)
            ->get([
                'ch_id',
                'ch_checkout_id',
                'ch_customer_name',
                'ch_amount',
                'ch_approval_status',
                'ch_fulfillment_status',
                'ch_paid_at',
                'created_at',
            ]);

        foreach ($orders as $order) {
            $orderId = (int) $order->ch_id;
            if ($orderId <= 0) {
                continue;
            }

            $customerName = trim((string) ($order->ch_customer_name ?? 'Customer'));
            $checkoutId = trim((string) ($order->ch_checkout_id ?? ''));
            $amount = (float) ($order->ch_amount ?? 0);
            $approvalStatus = (string) ($order->ch_approval_status ?? 'pending_approval');
            $fulfillmentStatus = (string) ($order->ch_fulfillment_status ?? 'pending');
            $createdAt = $order->ch_paid_at ?? $order->created_at ?? now();

            $severity = $approvalStatus === 'pending_approval' ? 'warning' : 'info';
            if (in_array($fulfillmentStatus, ['cancelled', 'refunded'], true)) {
                $severity = 'critical';
            }

            AdminNotification::query()->firstOrCreate(
                [
                    'an_type' => 'order_created',
                    'an_source_type' => 'order',
                    'an_source_id' => $orderId,
                ],
                [
                    'an_severity' => $severity,
                    'an_title' => 'Order Update',
                    'an_message' => sprintf(
                        '%s order %s (%s).',
                        $customerName !== '' ? $customerName : 'Customer',
                        $checkoutId !== '' ? $checkoutId : '#' . $orderId,
                        'PHP ' . number_format($amount, 2)
                    ),
                    'an_href' => '/admin/orders',
                    'an_payload' => [
                        'order_id' => $orderId,
                        'checkout_id' => $checkoutId,
                        'customer_name' => $customerName,
                        'amount' => $amount,
                        'approval_status' => $approvalStatus,
                        'fulfillment_status' => $fulfillmentStatus,
                        'seeded' => true,
                    ],
                    'an_created_at' => $createdAt,
                ]
            );
        }
    }

    private function canAdminSeeNotification(Admin $admin, AdminNotification $notification): bool
    {
        if ((int) $admin->user_level_id === 1) {
            return true;
        }

        $href = (string) ($notification->an_href ?? '/admin/orders');
        $permission = AdminAccess::permissionForPath($href);

        return $permission === null || AdminAccess::hasPermission($admin, $permission);
    }

    public function pusherAuth(Request $request)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'socket_id' => 'required|string|max:100',
            'channel_name' => 'required|string|max:255',
        ]);

        $channelName = (string) $validated['channel_name'];
        if (!Str::startsWith($channelName, 'private-admin-orders')) {
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

    public function index(Request $request)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'filter' => 'nullable|string|max:40',
            'q' => 'nullable|string|max:120',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $filter = $this->normalizeFilter((string) ($validated['filter'] ?? 'all'));
        $search = trim((string) ($validated['q'] ?? ''));
        $perPage = (int) ($validated['per_page'] ?? 20);

        $query = CheckoutHistory::query()
            ->select([
                'ch_id',
                'ch_customer_id',
                'ch_checkout_id',
                'ch_status',
                'ch_approval_status',
                'ch_approval_notes',
                'ch_approved_by',
                'ch_approved_at',
                'ch_fulfillment_status',
                'ch_courier',
                'ch_tracking_no',
                'ch_shipment_status',
                'ch_shipped_at',
                'ch_product_name',
                'ch_product_id',
                'ch_product_sku',
                'ch_product_pv',
                'ch_earned_pv',
                'ch_pv_posted_at',
                'ch_product_image',
                'ch_quantity',
                'ch_amount',
                'ch_payment_method',
                'ch_customer_name',
                'ch_customer_email',
                'ch_customer_phone',
                'ch_customer_address',
                'ch_paid_at',
                'created_at',
                'updated_at',
            ])
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($q) use ($search) {
                    $q->where('ch_checkout_id', 'like', "%{$search}%")
                        ->orWhere('ch_product_name', 'like', "%{$search}%")
                        ->orWhere('ch_customer_name', 'like', "%{$search}%")
                        ->orWhere('ch_customer_email', 'like', "%{$search}%");
                });
            });

        $this->applyFilter($query, $filter);

        $paginated = $query
            ->orderByDesc('ch_paid_at')
            ->orderByDesc('ch_id')
            ->paginate($perPage);

        $items = collect($paginated->items())->map(function (CheckoutHistory $order) {
            $sla = $this->computeSla($order);
            $shipmentPayload = $order->ch_shipment_payload;
            if (is_string($shipmentPayload) && trim($shipmentPayload) !== '') {
                $decodedPayload = json_decode($shipmentPayload, true);
                $shipmentPayload = is_array($decodedPayload) ? $decodedPayload : [];
            }
            if (!is_array($shipmentPayload)) {
                $shipmentPayload = [];
            }

            $trackingNo = $order->ch_tracking_no ?: $this->extractTrackingNoFromShipment($shipmentPayload);
            $shipmentStatus = $order->ch_shipment_status ?: $this->extractShipmentStatus($shipmentPayload);

            return [
                'id' => (int) $order->ch_id,
                'customer_id' => (int) $order->ch_customer_id,
                'checkout_id' => $order->ch_checkout_id,
                'payment_status' => $order->ch_status,
                'approval_status' => $order->ch_approval_status ?? 'pending_approval',
                'approval_notes' => $order->ch_approval_notes,
                'approved_by' => $order->ch_approved_by ? (int) $order->ch_approved_by : null,
                'approved_at' => optional($order->ch_approved_at)->toDateTimeString(),
                'fulfillment_status' => $order->ch_fulfillment_status ?? 'pending',
                'courier' => $order->ch_courier,
                'tracking_no' => $trackingNo,
                'shipment_status' => $shipmentStatus,
                'shipment_payload' => !empty($shipmentPayload) ? $shipmentPayload : null,
                'shipped_at' => optional($order->ch_shipped_at)->toDateTimeString(),
                'product_name' => $order->ch_product_name ?? ($order->ch_description ?? 'Order Item'),
                'product_id' => $order->ch_product_id ? (int) $order->ch_product_id : null,
                'product_sku' => $order->ch_product_sku,
                'product_pv' => (float) ($order->ch_product_pv ?? 0),
                'earned_pv' => (float) ($order->ch_earned_pv ?? 0),
                'pv_posted_at' => optional($order->ch_pv_posted_at)->toDateTimeString(),
                'product_image' => $order->ch_product_image,
                'quantity' => (int) $order->ch_quantity,
                'amount' => (float) $order->ch_amount,
                'payment_method' => $order->ch_payment_method,
                'customer_name' => $order->ch_customer_name,
                'customer_email' => $order->ch_customer_email,
                'customer_phone' => $order->ch_customer_phone,
                'customer_address' => $order->ch_customer_address,
                'paid_at' => optional($order->ch_paid_at)->toDateTimeString(),
                'created_at' => optional($order->created_at)->toDateTimeString(),
                'updated_at' => optional($order->updated_at)->toDateTimeString(),
                'sla' => $sla,
            ];
        })->values();

        return response()->json([
            'orders' => $items,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'from' => $paginated->firstItem(),
                'to' => $paginated->lastItem(),
            ],
            'counts' => $this->counts(),
        ]);
    }

    public function approve(Request $request, int $id)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!$this->canApprove($admin)) {
            return response()->json(['message' => 'Forbidden: approval access is limited.'], 403);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        $order = CheckoutHistory::query()->where('ch_id', $id)->firstOrFail();

        DB::transaction(function () use ($order, $admin, $validated) {
            $order->fill([
                'ch_approval_status' => 'approved',
                'ch_approval_notes' => $validated['notes'] ?? null,
                'ch_approved_by' => (int) $admin->id,
                'ch_approved_at' => now(),
                'ch_fulfillment_status' => $order->ch_fulfillment_status === 'pending' ? 'processing' : $order->ch_fulfillment_status,
            ])->save();

            $this->postPvIfNeeded($order, $admin);
        });

        $this->sendCustomerOrderStatusEmail($order, 'approval_approved');

        return response()->json(['message' => 'Order approved.']);
    }

    public function reject(Request $request, int $id)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!$this->canApprove($admin)) {
            return response()->json(['message' => 'Forbidden: approval access is limited.'], 403);
        }

        $validated = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        $order = CheckoutHistory::query()->where('ch_id', $id)->firstOrFail();

        DB::transaction(function () use ($order, $admin, $validated) {
            $order->fill([
                'ch_approval_status' => 'rejected',
                'ch_approval_notes' => $validated['notes'] ?? null,
                'ch_approved_by' => (int) $admin->id,
                'ch_approved_at' => now(),
                'ch_fulfillment_status' => 'cancelled',
            ])->save();

            DirectReferralCommission::cancelPendingForOrder(
                $order,
                (int) $admin->id,
                'Direct referral commission cancelled because the order was rejected.'
            );
        });

        $this->sendCustomerOrderStatusEmail($order, 'approval_rejected');

        return response()->json(['message' => 'Order rejected.']);
    }

    public function updateStatus(Request $request, int $id)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!$this->canUpdateFulfillment($admin)) {
            return response()->json(['message' => 'Forbidden: tracking access is limited.'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:pending,processing,packed,shipped,out_for_delivery,delivered,cancelled,refunded',
        ]);

        $order = CheckoutHistory::query()->where('ch_id', $id)->firstOrFail();
        if (($order->ch_approval_status ?? 'pending_approval') !== 'approved') {
            return response()->json(['message' => 'Order must be approved before fulfillment tracking updates.'], 422);
        }
        $previousStatus = (string) ($order->ch_fulfillment_status ?? 'pending');
        $order->ch_fulfillment_status = $validated['status'];
        $order->save();

        if ($validated['status'] === 'delivered') {
            DirectReferralCommission::releaseAvailableForOrder($order, (int) $admin->id);
        } elseif (in_array($validated['status'], ['cancelled', 'refunded'], true)) {
            DirectReferralCommission::cancelPendingForOrder(
                $order,
                (int) $admin->id,
                'Direct referral commission cancelled because the order was marked as ' . $validated['status'] . '.'
            );
        }

        $shippingResult = $this->bookShipmentOnShipped($order, (string) $validated['status']);

        if ($previousStatus !== (string) $order->ch_fulfillment_status) {
            $this->sendCustomerOrderStatusEmail($order, 'fulfillment_status');
        }

        $message = 'Order status updated.';
        if (($shippingResult['state'] ?? '') === 'booked') {
            $label = strtoupper((string) ($shippingResult['courier'] ?? 'courier'));
            $message = "Order status updated. {$label} shipment booked.";
        } elseif (($shippingResult['state'] ?? '') === 'failed') {
            $label = strtoupper((string) ($shippingResult['courier'] ?? 'courier'));
            $message = "Order status updated. {$label} booking failed.";
        }

        return response()->json([
            'message' => $message,
            'shipping' => $shippingResult,
        ]);
    }

    public function updateShipmentStatus(Request $request, int $id)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!$this->canUpdateFulfillment($admin)) {
            return response()->json(['message' => 'Forbidden: tracking access is limited.'], 403);
        }

        $validated = $request->validate([
            'shipment_status' => 'required|in:for_pickup,picked_up,in_transit,out_for_delivery,delivered,failed_delivery,returned_to_sender,cancelled',
            'courier' => 'nullable|in:jnt,xde',
        ]);

        $order = CheckoutHistory::query()->where('ch_id', $id)->firstOrFail();
        if (($order->ch_approval_status ?? 'pending_approval') !== 'approved') {
            return response()->json(['message' => 'Order must be approved before shipment tracking updates.'], 422);
        }

        $shipmentStatus = (string) $validated['shipment_status'];
        $previousShipmentStatus = (string) ($order->ch_shipment_status ?? '');
        $selectedCourier = $this->normalizeCourier($validated['courier'] ?? null);
        if ($selectedCourier !== null) {
            $order->ch_courier = $selectedCourier;
        } elseif (!$this->normalizeCourier($order->ch_courier)) {
            $order->ch_courier = $this->defaultCourier();
        }
        $order->ch_shipment_status = $shipmentStatus;

        if (in_array($shipmentStatus, ['picked_up', 'in_transit', 'for_pickup'], true)) {
            $order->ch_fulfillment_status = 'shipped';
            if (!$order->ch_shipped_at) {
                $order->ch_shipped_at = now();
            }
        } elseif ($shipmentStatus === 'out_for_delivery') {
            $order->ch_fulfillment_status = 'out_for_delivery';
        } elseif ($shipmentStatus === 'delivered') {
            $order->ch_fulfillment_status = 'delivered';
        } elseif (in_array($shipmentStatus, ['failed_delivery', 'returned_to_sender'], true)) {
            $order->ch_fulfillment_status = 'cancelled';
        }

        $order->save();

        if ($shipmentStatus === 'delivered') {
            DirectReferralCommission::releaseAvailableForOrder($order, (int) $admin->id);
        } elseif (in_array($shipmentStatus, ['failed_delivery', 'returned_to_sender', 'cancelled'], true)) {
            DirectReferralCommission::cancelPendingForOrder(
                $order,
                (int) $admin->id,
                'Direct referral commission cancelled because the shipment status became ' . $shipmentStatus . '.'
            );
        }

        if ($previousShipmentStatus !== (string) $order->ch_shipment_status) {
            $this->sendCustomerOrderStatusEmail($order, 'shipment_status');
        }

        return response()->json([
            'message' => 'Shipment status updated.',
            'order_id' => (int) $order->ch_id,
            'shipment_status' => $order->ch_shipment_status,
            'fulfillment_status' => $order->ch_fulfillment_status,
        ]);
    }

    private function bookShipmentOnShipped(CheckoutHistory $order, string $status): array
    {
        if ($status !== 'shipped') {
            return ['state' => 'skipped', 'reason' => 'status_not_shipped'];
        }

        $courier = $this->normalizeCourier($order->ch_courier) ?? $this->defaultCourier();
        if ($courier === null) {
            return ['state' => 'skipped', 'reason' => 'no_courier_configured'];
        }

        if (!empty($order->ch_tracking_no) && strtolower((string) $order->ch_courier) === $courier) {
            return [
                'state' => 'skipped',
                'reason' => 'already_booked',
                'courier' => $courier,
                'tracking_no' => (string) $order->ch_tracking_no,
            ];
        }

        return $this->bookCourierShipment($order, $courier);
    }

    private function bookCourierShipment(CheckoutHistory $order, string $courier): array
    {
        $payload = [
            'reference_no' => (string) ($order->ch_checkout_id ?? ''),
            'recipient_name' => (string) ($order->ch_customer_name ?? ''),
            'recipient_phone' => (string) ($order->ch_customer_phone ?? ''),
            'recipient_email' => (string) ($order->ch_customer_email ?? ''),
            'recipient_address' => (string) ($order->ch_customer_address ?? ''),
            'declared_value' => (float) ($order->ch_amount ?? 0),
            'payment_method' => (string) ($order->ch_payment_method ?? ''),
            'items' => [[
                'name' => (string) ($order->ch_product_name ?? 'Order Item'),
                'quantity' => (int) ($order->ch_quantity ?? 1),
            ]],
        ];

        try {
            $response = match ($courier) {
                'jnt' => $this->jntShippingService->bookShipment($payload),
                default => $this->xdeShippingService->bookShipment($payload),
            };
            $trackingNo = $this->extractTrackingNoFromShipment($response);
            $shipmentStatus = $this->extractShipmentStatus($response);

            $order->ch_courier = $courier;
            if ($trackingNo !== null) {
                $order->ch_tracking_no = $trackingNo;
            }
            if ($shipmentStatus !== null) {
                $order->ch_shipment_status = $shipmentStatus;
            }
            $order->ch_shipment_payload = $response;
            if ($trackingNo !== null && !$order->ch_shipped_at) {
                $order->ch_shipped_at = now();
            }
            $order->save();

            return [
                'state' => 'booked',
                'courier' => $courier,
                'tracking_no' => $order->ch_tracking_no,
                'shipment_status' => $order->ch_shipment_status,
            ];
        } catch (RuntimeException $e) {
            Log::warning('Courier auto-booking failed on shipped status update.', [
                'order_id' => (int) $order->ch_id,
                'checkout_id' => (string) $order->ch_checkout_id,
                'courier' => $courier,
                'error' => $e->getMessage(),
            ]);

            return [
                'state' => 'failed',
                'courier' => $courier,
                'reason' => $courier . '_book_failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function normalizeCourier(mixed $courier): ?string
    {
        $normalized = strtolower(trim((string) $courier));
        return in_array($normalized, ['jnt', 'xde'], true) ? $normalized : null;
    }

    private function defaultCourier(): ?string
    {
        if ($this->hasCourierConfig('jnt')) {
            return 'jnt';
        }

        if ($this->hasCourierConfig('xde')) {
            return 'xde';
        }

        return null;
    }

    private function hasCourierConfig(string $courier): bool
    {
        return (string) config("services.{$courier}.base_url", '') !== ''
            && (string) config("services.{$courier}.api_key", '') !== ''
            && (string) config("services.{$courier}.token", '') !== '';
    }

    private function extractTrackingNoFromShipment(array $response): ?string
    {
        $candidates = [
            data_get($response, 'tracking_no'),
            data_get($response, 'tracking_number'),
            data_get($response, 'waybill_no'),
            data_get($response, 'waybillNo'),
            data_get($response, 'billCode'),
            data_get($response, 'txlogisticId'),
            data_get($response, 'awb'),
            data_get($response, 'data.tracking_no'),
            data_get($response, 'data.tracking_number'),
            data_get($response, 'data.waybill_no'),
            data_get($response, 'data.waybillNo'),
            data_get($response, 'data.billCode'),
            data_get($response, 'data.txlogisticId'),
            data_get($response, 'data.data.tracking_no'),
            data_get($response, 'data.data.tracking_number'),
            data_get($response, 'data.data.waybillNo'),
            data_get($response, 'data.data.billCode'),
            data_get($response, 'data.data.txlogisticId'),
            data_get($response, 'result.tracking_no'),
            data_get($response, 'result.tracking_number'),
            data_get($response, 'result.waybillNo'),
            data_get($response, 'result.billCode'),
            data_get($response, 'result.txlogisticId'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function extractShipmentStatus(array $response): ?string
    {
        $candidates = [
            data_get($response, 'status'),
            data_get($response, 'shipment_status'),
            data_get($response, 'data.status'),
            data_get($response, 'data.shipment_status'),
            data_get($response, 'result.status'),
            data_get($response, 'result.shipment_status'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return strtolower(trim($candidate));
            }
        }

        return null;
    }

    private function resolveAdmin(Request $request): ?Admin
    {
        $user = $request->user();
        return $user instanceof Admin ? $user : null;
    }

    private function canApprove(Admin $admin): bool
    {
        return in_array($this->roleFromAdmin($admin), ['super_admin', 'admin', 'merchant_admin'], true);
    }

    private function canUpdateFulfillment(Admin $admin): bool
    {
        return in_array($this->roleFromAdmin($admin), ['super_admin', 'admin', 'csr', 'merchant_admin'], true);
    }

    private function roleFromAdmin(Admin $admin): string
    {
        return match ((int) $admin->user_level_id) {
            1 => 'super_admin',
            2 => 'admin',
            3 => 'csr',
            4 => 'web_content',
            7 => 'merchant_admin',
            8 => 'supplier_admin',
            default => 'staff',
        };
    }

    private function applyFilter($query, string $filter): void
    {
        if ($filter === 'all' || $filter === '') {
            return;
        }

        if ($filter === 'pending') {
            $query->where(function ($q) {
                $q->where('ch_approval_status', 'pending_approval')
                    ->orWhere('ch_fulfillment_status', 'pending');
            });
            return;
        }

        if ($filter === 'processing') {
            $query->where('ch_fulfillment_status', 'processing');
            return;
        }

        if ($filter === 'paid') {
            $query->where('ch_status', 'paid');
            return;
        }

        if ($filter === 'packed') {
            $query->where('ch_fulfillment_status', 'packed');
            return;
        }

        if ($filter === 'shipped') {
            $query->where('ch_fulfillment_status', 'shipped');
            return;
        }

        if ($filter === 'out_for_delivery') {
            $query->where('ch_fulfillment_status', 'out_for_delivery');
            return;
        }

        if ($filter === 'delivered') {
            $query->where('ch_fulfillment_status', 'delivered');
            return;
        }

        if ($filter === 'cancelled') {
            $query->where('ch_fulfillment_status', 'cancelled');
            return;
        }

        if ($filter === 'refunded') {
            $query->where('ch_fulfillment_status', 'refunded');
            return;
        }

        if ($filter === 'failed_payments') {
            $query->whereIn('ch_status', ['failed', 'cancelled', 'expired']);
            return;
        }

        if ($filter === 'order_history' || $filter === 'completed') {
            $query->whereIn('ch_fulfillment_status', ['delivered', 'cancelled', 'refunded']);
            return;
        }

        $query->where('ch_fulfillment_status', $filter);
    }

    private function normalizeFilter(string $filter): string
    {
        $normalized = strtolower(trim($filter));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return match ($normalized) {
            'returned_refunded', 'returned', 'refund', 'refunds' => 'refunded',
            'history' => 'order_history',
            'deliverd' => 'delivered',
            'outfordelivery' => 'out_for_delivery',
            default => $normalized,
        };
    }

    private function counts(): array
    {
        $base = CheckoutHistory::query();

        return [
            'all' => (int) (clone $base)->count(),
            'pending' => (int) (clone $base)->where(function ($q) {
                $q->where('ch_approval_status', 'pending_approval')
                    ->orWhere('ch_fulfillment_status', 'pending');
            })->count(),
            'processing' => (int) (clone $base)->whereIn('ch_fulfillment_status', ['processing', 'packed', 'shipped', 'out_for_delivery'])->count(),
            'cancelled' => (int) (clone $base)->whereIn('ch_fulfillment_status', ['cancelled', 'refunded'])->count(),
            'completed' => (int) (clone $base)->where('ch_fulfillment_status', 'delivered')->count(),
        ];
    }

    private function computeSla(CheckoutHistory $order): array
    {
        $approvalStatus = (string) ($order->ch_approval_status ?? 'pending_approval');
        $fulfillment = (string) ($order->ch_fulfillment_status ?? 'pending');

        $key = $approvalStatus === 'pending_approval' ? 'pending_approval' : $fulfillment;

        $targets = [
            'pending_approval' => 45,
            'processing' => 240,
            'packed' => 720,
            'shipped' => 1440,
            'out_for_delivery' => 2880,
        ];

        $targetMinutes = $targets[$key] ?? null;
        if ($targetMinutes === null) {
            return [
                'key' => $key,
                'state' => 'no_sla',
                'target_minutes' => null,
                'elapsed_minutes' => null,
                'remaining_minutes' => null,
                'overdue_minutes' => null,
            ];
        }

        $baseTime = $order->updated_at ?? $order->created_at ?? now();
        $elapsedMinutes = max(0, (int) Carbon::parse($baseTime)->diffInMinutes(now()));
        $remaining = $targetMinutes - $elapsedMinutes;
        $overdue = $elapsedMinutes - $targetMinutes;

        $state = 'on_track';
        if ($overdue > 0) {
            $state = 'overdue';
        } elseif ($remaining <= max(15, (int) round($targetMinutes * 0.2))) {
            $state = 'due_soon';
        }

        return [
            'key' => $key,
            'state' => $state,
            'target_minutes' => $targetMinutes,
            'elapsed_minutes' => $elapsedMinutes,
            'remaining_minutes' => max(0, $remaining),
            'overdue_minutes' => max(0, $overdue),
        ];
    }

    private function postPvIfNeeded(CheckoutHistory $order, Admin $admin): void
    {
        $earnedPv = (float) ($order->ch_earned_pv ?? 0);
        if ($earnedPv <= 0 || $order->ch_pv_posted_at) {
            return;
        }

        $alreadyPosted = CustomerWalletLedger::query()
            ->where('wl_wallet_type', 'pv')
            ->where('wl_entry_type', 'credit')
            ->where('wl_source_type', 'order')
            ->where('wl_source_id', (int) $order->ch_id)
            ->exists();
        if ($alreadyPosted) {
            $order->ch_pv_posted_at = now();
            $order->save();
            return;
        }

        $customer = Customer::query()->where('c_userid', (int) $order->ch_customer_id)->lockForUpdate()->first();
        if (!$customer) {
            return;
        }

        $customer->c_gpv = (float) ($customer->c_gpv ?? 0) + $earnedPv;
        $customer->save();

        CustomerWalletLedger::create([
            'wl_customer_id' => (int) $customer->c_userid,
            'wl_wallet_type' => 'pv',
            'wl_entry_type' => 'credit',
            'wl_amount' => $earnedPv,
            'wl_source_type' => 'order',
            'wl_source_id' => (int) $order->ch_id,
            'wl_reference_no' => $order->ch_checkout_id,
            'wl_notes' => 'PV credit posted on order approval.',
            'wl_created_by' => (int) $admin->id,
        ]);

        $order->ch_pv_posted_at = now();
        $order->save();

        DirectAffiliatePerformanceBonus::awardEligibleMilestonesForBuyer($customer, $order, (int) $admin->id);
        GroupPurchaseBonus::awardForBuyer($customer, $order, (int) $admin->id);
    }

    private function sendCustomerOrderStatusEmail(CheckoutHistory $order, string $eventType): void
    {
        $recipient = trim((string) ($order->ch_customer_email ?? ''));
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $statusKey = $this->buildOrderNotificationKey($order, $eventType);
        if ($statusKey === '') {
            return;
        }

        $cacheKey = sprintf('order_status_email_sent:%d:%s', (int) $order->ch_id, $statusKey);
        if (!Cache::add($cacheKey, true, now()->addDays(30))) {
            return;
        }

        $payload = $this->buildOrderStatusEmailPayload($order, $eventType);
        if ($payload === null) {
            Cache::forget($cacheKey);
            return;
        }

        $mailRecipient = env('MAIL_TEST_TO') ?: $recipient;

        try {
            Mail::mailer('resend')->to($mailRecipient)->send(new OrderStatusUpdatedMail($payload));
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);
            Log::error('Order status email send failed.', [
                'order_id' => (int) $order->ch_id,
                'checkout_id' => (string) $order->ch_checkout_id,
                'recipient' => $mailRecipient,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
            report($e);
        }
    }

    private function buildOrderNotificationKey(CheckoutHistory $order, string $eventType): string
    {
        return match ($eventType) {
            'approval_approved' => 'approval:approved',
            'approval_rejected' => 'approval:rejected',
            'fulfillment_status' => 'fulfillment:' . strtolower((string) ($order->ch_fulfillment_status ?? '')),
            'shipment_status' => 'shipment:' . strtolower((string) ($order->ch_shipment_status ?? '')),
            default => '',
        };
    }

    private function buildOrderStatusEmailPayload(CheckoutHistory $order, string $eventType): ?array
    {
        $customerName = trim((string) ($order->ch_customer_name ?? 'Customer')) ?: 'Customer';
        $fulfillmentStatus = strtolower((string) ($order->ch_fulfillment_status ?? 'pending'));
        $shipmentStatus = strtolower((string) ($order->ch_shipment_status ?? ''));
        $trackingNo = trim((string) ($order->ch_tracking_no ?? ''));
        $courier = trim((string) ($order->ch_courier ?? ''));

        $title = 'Order Update';
        $subtitle = 'There is a new update for your AF Home order.';
        $badge = strtoupper(str_replace('_', ' ', $fulfillmentStatus));
        $badgeColor = 'background:#ffedd5;color:#c2410c;';
        $nextStep = 'You can keep your order number handy and track your delivery progress anytime.';

        if ($eventType === 'approval_approved') {
            $title = 'Order Approved';
            $subtitle = 'Your payment has been confirmed and your order is now being prepared.';
            $badge = 'APPROVED';
            $badgeColor = 'background:#dcfce7;color:#15803d;';
            $nextStep = 'Our team is now preparing your order for packing and shipment.';
        } elseif ($eventType === 'approval_rejected') {
            $title = 'Order Update';
            $subtitle = 'Your order was not approved. Please contact AF Home support for assistance.';
            $badge = 'REJECTED';
            $badgeColor = 'background:#fee2e2;color:#b91c1c;';
            $nextStep = 'If you need help reviewing this order, please contact the AF Home support team.';
        } elseif ($eventType === 'fulfillment_status') {
            [$title, $subtitle, $badgeColor, $nextStep] = match ($fulfillmentStatus) {
                'processing' => ['Order Is Processing', 'Your order is now in our active processing queue.', 'background:#dbeafe;color:#1d4ed8;', 'Our team is preparing your items and will notify you once shipment begins.'],
                'packed' => ['Order Packed', 'Good news. Your items are packed and almost ready to leave our warehouse.', 'background:#e0e7ff;color:#4338ca;', 'The next update you receive should be your shipment or delivery progress.'],
                'shipped' => ['Order Shipped', 'Your order is already on the way.', 'background:#ede9fe;color:#6d28d9;', 'Keep an eye on your tracking number for courier movement updates.'],
                'out_for_delivery' => ['Out for Delivery', 'Your order is already out for delivery and should arrive soon.', 'background:#ffedd5;color:#c2410c;', 'Please keep your line open in case the rider or courier needs to contact you.'],
                'delivered' => ['Order Delivered', 'Your AF Home order has been marked as delivered.', 'background:#dcfce7;color:#15803d;', 'If anything looks incorrect with the delivery, please contact support right away.'],
                'cancelled' => ['Order Cancelled', 'Your order has been cancelled.', 'background:#fee2e2;color:#b91c1c;', 'Please contact support if you think this cancellation was made in error.'],
                'refunded' => ['Order Refunded', 'Your order has been marked as refunded.', 'background:#e5e7eb;color:#374151;', 'Please allow additional time for the refund to reflect depending on your payment channel.'],
                default => ['Order Update', 'Your order status has changed.', 'background:#fef3c7;color:#92400e;', 'You can track your order anytime using your checkout details.'],
            };
            $badge = strtoupper(str_replace('_', ' ', $fulfillmentStatus));
        } elseif ($eventType === 'shipment_status') {
            [$title, $subtitle, $badgeColor, $nextStep] = match ($shipmentStatus) {
                'for_pickup' => ['Shipment Scheduled', 'Your parcel is scheduled for courier pickup.', 'background:#ede9fe;color:#6d28d9;', 'We will notify you again once the courier has picked up your order.'],
                'picked_up' => ['Shipment Picked Up', 'The courier has already picked up your order.', 'background:#ede9fe;color:#6d28d9;', 'Your order is now moving through the delivery network.'],
                'in_transit' => ['Shipment In Transit', 'Your package is currently in transit.', 'background:#ede9fe;color:#6d28d9;', 'The next update should be once your parcel is out for delivery.'],
                'out_for_delivery' => ['Shipment Out for Delivery', 'Your package is with the courier and arriving soon.', 'background:#ffedd5;color:#c2410c;', 'Please be ready to receive the order today if delivery is successful.'],
                'delivered' => ['Shipment Delivered', 'The courier marked your shipment as delivered.', 'background:#dcfce7;color:#15803d;', 'If you have any concern about the received items, contact AF Home support.'],
                'cancelled' => ['Shipment Cancelled', 'The courier booking for your shipment has been cancelled.', 'background:#fee2e2;color:#b91c1c;', 'Please contact AF Home support if you need the order rebooked or reviewed.'],
                'failed_delivery' => ['Delivery Attempt Failed', 'The courier was not able to complete the delivery attempt.', 'background:#fee2e2;color:#b91c1c;', 'Please wait for the next courier instruction or contact support for help.'],
                'returned_to_sender' => ['Shipment Returned', 'The shipment was returned to sender.', 'background:#e5e7eb;color:#374151;', 'Please contact AF Home support so the order can be reviewed with you.'],
                default => ['Shipment Update', 'There is a new courier update for your order.', 'background:#fef3c7;color:#92400e;', 'You can check your latest tracking details using your order reference.'],
            };
            $badge = strtoupper(str_replace('_', ' ', $shipmentStatus !== '' ? $shipmentStatus : $fulfillmentStatus));
        }

        return [
            'customer_name' => $customerName,
            'title' => $title,
            'subtitle' => $subtitle,
            'badge' => $badge,
            'badge_color' => $badgeColor,
            'next_step' => $nextStep,
            'checkout_id' => (string) ($order->ch_checkout_id ?? '-'),
            'order_number' => (string) ($order->ch_checkout_id ?? '-'),
            'product_name' => (string) ($order->ch_product_name ?: ($order->ch_description ?? 'Order Item')),
            'quantity' => max(1, (int) ($order->ch_quantity ?? 1)),
            'amount' => (float) ($order->ch_amount ?? 0),
            'payment_method' => (string) ($order->ch_payment_method ?? '-'),
            'shipping_address' => (string) ($order->ch_customer_address ?? '-'),
            'fulfillment_status' => $fulfillmentStatus,
            'shipment_status' => $shipmentStatus,
            'tracking_no' => $trackingNo !== '' ? $trackingNo : null,
            'courier' => $courier !== '' ? strtoupper($courier) : null,
            'shipped_at' => optional($order->ch_shipped_at)->toDateTimeString(),
            'paid_at' => optional($order->ch_paid_at)->toDateTimeString(),
            'approval_notes' => (string) ($order->ch_approval_notes ?? ''),
        ];
    }
}
