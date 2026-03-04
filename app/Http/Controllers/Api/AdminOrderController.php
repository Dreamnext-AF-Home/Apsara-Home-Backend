<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\CheckoutHistory;
use App\Models\Customer;
use App\Models\CustomerWalletLedger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOrderController extends Controller
{
    public function notifications(Request $request)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $now = now();
        $approvalSlaCutoff = $now->copy()->subMinutes(45);

        $pendingApprovalCount = (int) CheckoutHistory::query()
            ->where('ch_approval_status', 'pending_approval')
            ->count();

        $overdueApprovalCount = (int) CheckoutHistory::query()
            ->where('ch_approval_status', 'pending_approval')
            ->where('created_at', '<=', $approvalSlaCutoff)
            ->count();

        $failedPaymentsCount = (int) CheckoutHistory::query()
            ->whereIn('ch_status', ['failed', 'cancelled', 'expired'])
            ->count();

        $fulfillmentQueueCount = (int) CheckoutHistory::query()
            ->where('ch_approval_status', 'approved')
            ->whereIn('ch_fulfillment_status', ['processing', 'packed', 'shipped', 'out_for_delivery'])
            ->count();

        $items = array_values(array_filter([
            [
                'id' => 'pending_approval',
                'title' => 'Pending Order Approvals',
                'description' => $pendingApprovalCount > 0
                    ? $pendingApprovalCount . ' order(s) are waiting for approval.'
                    : 'No pending approvals right now.',
                'count' => $pendingApprovalCount,
                'severity' => $pendingApprovalCount > 0 ? 'warning' : 'success',
                'href' => '/admin/orders/pending',
            ],
            [
                'id' => 'overdue_sla',
                'title' => 'Approval SLA Overdue',
                'description' => $overdueApprovalCount > 0
                    ? $overdueApprovalCount . ' order(s) exceeded the 45-minute approval SLA.'
                    : 'All pending approvals are within SLA.',
                'count' => $overdueApprovalCount,
                'severity' => $overdueApprovalCount > 0 ? 'critical' : 'success',
                'href' => '/admin/orders/pending',
            ],
            [
                'id' => 'failed_payments',
                'title' => 'Failed Payments',
                'description' => $failedPaymentsCount > 0
                    ? $failedPaymentsCount . ' checkout(s) are tagged as failed/cancelled/expired.'
                    : 'No failed payment records detected.',
                'count' => $failedPaymentsCount,
                'severity' => $failedPaymentsCount > 0 ? 'warning' : 'success',
                'href' => '/admin/orders/failed_payments',
            ],
            [
                'id' => 'fulfillment_queue',
                'title' => 'Fulfillment In Progress',
                'description' => $fulfillmentQueueCount > 0
                    ? $fulfillmentQueueCount . ' approved order(s) are in fulfillment stages.'
                    : 'No active fulfillment queue.',
                'count' => $fulfillmentQueueCount,
                'severity' => 'info',
                'href' => '/admin/orders/processing',
            ],
        ], fn ($item) => is_array($item)));

        $unreadCount = $pendingApprovalCount + $overdueApprovalCount + $failedPaymentsCount;

        return response()->json([
            'unread_count' => $unreadCount,
            'items' => $items,
            'generated_at' => $now->toDateTimeString(),
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

        $order->fill([
            'ch_approval_status' => 'rejected',
            'ch_approval_notes' => $validated['notes'] ?? null,
            'ch_approved_by' => (int) $admin->id,
            'ch_approved_at' => now(),
            'ch_fulfillment_status' => 'cancelled',
        ])->save();

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
        $order->ch_fulfillment_status = $validated['status'];
        $order->save();

        return response()->json(['message' => 'Order status updated.']);
    }

    private function resolveAdmin(Request $request): ?Admin
    {
        $user = $request->user();
        return $user instanceof Admin ? $user : null;
    }

    private function canApprove(Admin $admin): bool
    {
        return in_array($this->roleFromAdmin($admin), ['super_admin', 'admin'], true);
    }

    private function canUpdateFulfillment(Admin $admin): bool
    {
        return in_array($this->roleFromAdmin($admin), ['super_admin', 'admin', 'csr'], true);
    }

    private function roleFromAdmin(Admin $admin): string
    {
        return match ((int) $admin->user_level_id) {
            1 => 'super_admin',
            2 => 'admin',
            3 => 'csr',
            4 => 'web_content',
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
    }
}
