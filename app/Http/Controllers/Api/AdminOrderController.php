<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\CheckoutHistory;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
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

        $filter = strtolower((string) ($validated['filter'] ?? 'all'));
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

        $validated = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        $order = CheckoutHistory::query()->where('ch_id', $id)->firstOrFail();

        $order->fill([
            'ch_approval_status' => 'approved',
            'ch_approval_notes' => $validated['notes'] ?? null,
            'ch_approved_by' => (int) $admin->id,
            'ch_approved_at' => now(),
            'ch_fulfillment_status' => $order->ch_fulfillment_status === 'pending' ? 'processing' : $order->ch_fulfillment_status,
        ])->save();

        return response()->json(['message' => 'Order approved.']);
    }

    public function reject(Request $request, int $id)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
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

        $validated = $request->validate([
            'status' => 'required|in:pending,processing,packed,shipped,out_for_delivery,delivered,cancelled,refunded',
        ]);

        $order = CheckoutHistory::query()->where('ch_id', $id)->firstOrFail();
        $order->ch_fulfillment_status = $validated['status'];
        $order->save();

        return response()->json(['message' => 'Order status updated.']);
    }

    private function resolveAdmin(Request $request): ?Admin
    {
        $user = $request->user();
        return $user instanceof Admin ? $user : null;
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
            $query->whereIn('ch_fulfillment_status', ['processing', 'packed', 'shipped', 'out_for_delivery']);
            return;
        }

        if ($filter === 'cancelled') {
            $query->whereIn('ch_fulfillment_status', ['cancelled', 'refunded']);
            return;
        }

        if ($filter === 'completed') {
            $query->where('ch_fulfillment_status', 'delivered');
            return;
        }

        $query->where('ch_fulfillment_status', $filter);
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
}
