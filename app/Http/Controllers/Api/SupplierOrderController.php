<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckoutHistory;
use App\Models\ProductBrand;
use App\Models\Supplier;
use App\Models\SupplierUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierOrderController extends Controller
{
    public function index(Request $request)
    {
        $supplierUser = $this->resolveSupplierUser($request);
        if (! $supplierUser) {
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

        $supplierId = (int) $supplierUser->su_supplier;
        $brandTypeValue = $supplierId > 0 ? $this->resolveSupplierBrandType($supplierId) : 0;

        $query = $this->supplierOrdersBaseQuery($supplierId, $brandTypeValue)
            ->when($search !== '', function ($builder) use ($search) {
                $builder->where(function ($q) use ($search) {
                    $q->where('tbl_checkout_history.ch_checkout_id', 'like', "%{$search}%")
                        ->orWhere('tbl_checkout_history.ch_product_name', 'like', "%{$search}%")
                        ->orWhere('tbl_checkout_history.ch_customer_name', 'like', "%{$search}%")
                        ->orWhere('tbl_checkout_history.ch_customer_email', 'like', "%{$search}%");
                });
            });

        $this->applyFilter($query, $filter);

        $paginated = $query
            ->orderByDesc('tbl_checkout_history.ch_paid_at')
            ->orderByDesc('tbl_checkout_history.ch_id')
            ->paginate($perPage);

        $items = collect($paginated->items())->map(function ($row) {
            return [
                'id' => (int) $row->ch_id,
                'customer_id' => (int) $row->ch_customer_id,
                'checkout_id' => $row->ch_checkout_id,
                'payment_status' => $row->ch_status,
                'approval_status' => $row->ch_approval_status ?? 'pending_approval',
                'approval_notes' => $row->ch_approval_notes,
                'approved_by' => $row->ch_approved_by ? (int) $row->ch_approved_by : null,
                'approved_at' => optional($row->ch_approved_at)->toDateTimeString(),
                'fulfillment_status' => $row->ch_fulfillment_status ?? 'pending',
                'courier' => $row->ch_courier,
                'tracking_no' => $row->ch_tracking_no,
                'shipment_status' => $row->ch_shipment_status,
                'shipped_at' => optional($row->ch_shipped_at)->toDateTimeString(),
                'product_name' => $row->ch_product_name ?? ($row->ch_description ?? 'Order Item'),
                'product_description' => $row->product_description ?? null,
                'product_image' => $row->ch_product_image,
                'quantity' => (int) $row->ch_quantity,
                'amount' => (float) $row->ch_amount,
                'payment_method' => $row->ch_payment_method,
                'customer_name' => $row->ch_customer_name,
                'customer_email' => $row->ch_customer_email,
                'customer_phone' => $row->ch_customer_phone,
                'customer_address' => $row->ch_customer_address,
                'paid_at' => optional($row->ch_paid_at)->toDateTimeString(),
                'created_at' => optional($row->created_at)->toDateTimeString(),
                'updated_at' => optional($row->updated_at)->toDateTimeString(),
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
            'counts' => $this->counts($supplierId, $brandTypeValue),
        ]);
    }

    public function updateFulfillment(Request $request, int $id)
    {
        $supplierUser = $this->resolveSupplierUser($request);
        if (! $supplierUser) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'fulfillment_status' => 'required|in:processing,packed,shipped,out_for_delivery,delivered,cancelled,returned',
        ]);

        $order = $this->findScopedOrder($supplierUser, $id);
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if (($order->ch_approval_status ?? 'pending_approval') !== 'approved') {
            return response()->json(['message' => 'Order must be approved before supplier fulfillment can start.'], 422);
        }

        $status = (string) $validated['fulfillment_status'];

        DB::transaction(function () use ($order, $status) {
            $order->ch_fulfillment_status = $status;

            if ($status === 'shipped') {
                $order->ch_shipment_status = $order->ch_shipment_status ?: 'in_transit';
                $order->ch_shipped_at = $order->ch_shipped_at ?: now();
            } elseif ($status === 'out_for_delivery') {
                $order->ch_shipment_status = 'out_for_delivery';
                $order->ch_shipped_at = $order->ch_shipped_at ?: now();
            } elseif ($status === 'delivered') {
                $order->ch_shipment_status = 'delivered';
                $order->ch_shipped_at = $order->ch_shipped_at ?: now();
            } elseif ($status === 'cancelled') {
                $order->ch_shipment_status = 'cancelled';
            } elseif ($status === 'returned') {
                $order->ch_shipment_status = 'returned_to_sender';
            }

            $payload = is_array($order->ch_shipment_payload) ? $order->ch_shipment_payload : [];
            $payload['supplier_portal'] = array_merge(
                is_array($payload['supplier_portal'] ?? null) ? $payload['supplier_portal'] : [],
                [
                    'last_fulfillment_status' => $status,
                    'updated_at' => now()->toDateTimeString(),
                ]
            );
            $order->ch_shipment_payload = $payload;
            $order->save();
        });

        return response()->json([
            'message' => 'Supplier fulfillment status updated successfully.',
            'order' => $this->mapOrder($order->fresh()),
        ]);
    }

    public function updateTracking(Request $request, int $id)
    {
        $supplierUser = $this->resolveSupplierUser($request);
        if (! $supplierUser) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'courier' => 'required|string|max:80',
            'tracking_no' => 'required|string|max:120',
            'shipment_status' => 'nullable|in:for_pickup,picked_up,in_transit,out_for_delivery,delivered,failed_delivery,cancelled,returned_to_sender',
        ]);

        $order = $this->findScopedOrder($supplierUser, $id);
        if (! $order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        if (($order->ch_approval_status ?? 'pending_approval') !== 'approved') {
            return response()->json(['message' => 'Order must be approved before tracking can be added.'], 422);
        }

        $shipmentStatus = (string) ($validated['shipment_status'] ?? 'in_transit');

        DB::transaction(function () use ($order, $validated, $shipmentStatus) {
            $order->ch_courier = strtolower(trim((string) $validated['courier']));
            $order->ch_tracking_no = trim((string) $validated['tracking_no']);
            $order->ch_shipment_status = $shipmentStatus;
            $order->ch_shipped_at = $order->ch_shipped_at ?: now();

            if (in_array($shipmentStatus, ['for_pickup', 'picked_up', 'in_transit'], true)) {
                $order->ch_fulfillment_status = 'shipped';
            } elseif ($shipmentStatus === 'out_for_delivery') {
                $order->ch_fulfillment_status = 'out_for_delivery';
            } elseif ($shipmentStatus === 'delivered') {
                $order->ch_fulfillment_status = 'delivered';
            } elseif ($shipmentStatus === 'cancelled') {
                $order->ch_fulfillment_status = 'cancelled';
            } elseif ($shipmentStatus === 'returned_to_sender') {
                $order->ch_fulfillment_status = 'returned';
            }

            $payload = is_array($order->ch_shipment_payload) ? $order->ch_shipment_payload : [];
            $payload['supplier_portal'] = array_merge(
                is_array($payload['supplier_portal'] ?? null) ? $payload['supplier_portal'] : [],
                [
                    'courier' => $order->ch_courier,
                    'tracking_no' => $order->ch_tracking_no,
                    'shipment_status' => $order->ch_shipment_status,
                    'updated_at' => now()->toDateTimeString(),
                ]
            );
            $order->ch_shipment_payload = $payload;
            $order->save();
        });

        return response()->json([
            'message' => 'Tracking details updated successfully.',
            'order' => $this->mapOrder($order->fresh()),
        ]);
    }

    private function resolveSupplierUser(Request $request): ?SupplierUser
    {
        $user = $request->user();
        return $user instanceof SupplierUser ? $user : null;
    }

    private function resolveSupplierBrandType(int $supplierId): int
    {
        if ($supplierId <= 0) {
            return 0;
        }

        $supplier = Supplier::query()->find($supplierId);
        if (! $supplier) {
            return 0;
        }

        $candidates = [
            (string) ($supplier->s_company ?? ''),
            (string) ($supplier->s_name ?? ''),
        ];
        $normalizedCandidates = collect($candidates)
            ->map(fn ($value) => strtolower(preg_replace('/[^a-z0-9]/i', '', trim($value)) ?? ''))
            ->filter(fn ($value) => $value !== '')
            ->values();

        if ($normalizedCandidates->isEmpty()) {
            return 0;
        }

        $brands = ProductBrand::query()->select(['pb_id', 'pb_name'])->get();
        foreach ($brands as $brand) {
            $brandKey = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($brand->pb_name ?? '')) ?? '');
            if ($brandKey === '') {
                continue;
            }
            foreach ($normalizedCandidates as $candidate) {
                if ($candidate !== '' && $candidate === $brandKey) {
                    return (int) $brand->pb_id;
                }
            }
        }

        $bestId = 0;
        $bestScore = 0;
        $bestLen = 0;
        foreach ($brands as $brand) {
            $brandKey = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) ($brand->pb_name ?? '')) ?? '');
            if ($brandKey === '' || strlen($brandKey) < 2) {
                continue;
            }

            foreach ($normalizedCandidates as $candidate) {
                if ($candidate === '') {
                    continue;
                }
                $score = 0;
                if ($candidate === $brandKey) {
                    $score = 3;
                } elseif (str_contains($candidate, $brandKey)) {
                    $score = 2;
                } elseif (str_contains($brandKey, $candidate)) {
                    $score = 1;
                }

                if ($score > 0) {
                    $len = strlen($brandKey);
                    if ($score > $bestScore || ($score === $bestScore && $len > $bestLen)) {
                        $bestScore = $score;
                        $bestLen = $len;
                        $bestId = (int) $brand->pb_id;
                    }
                }
            }
        }

        return $bestId;
    }

    private function applyFilter($query, string $filter): void
    {
        if ($filter === 'all' || $filter === '') {
            return;
        }

        if ($filter === 'to_pay') {
            $query->whereIn('tbl_checkout_history.ch_status', ['pending', 'unpaid', 'failed', 'cancelled', 'expired', 'active']);
            return;
        }

        if ($filter === 'to_ship') {
            $query->whereIn('tbl_checkout_history.ch_fulfillment_status', ['processing', 'packed']);
            return;
        }

        if ($filter === 'to_receive') {
            $query->whereIn('tbl_checkout_history.ch_fulfillment_status', ['shipped', 'out_for_delivery']);
            return;
        }

        if ($filter === 'completed') {
            $query->where('tbl_checkout_history.ch_fulfillment_status', 'delivered');
            return;
        }

        if ($filter === 'cancelled') {
            $query->whereIn('tbl_checkout_history.ch_fulfillment_status', ['cancelled', 'refunded']);
            return;
        }

        if ($filter === 'return') {
            $query->whereIn('tbl_checkout_history.ch_fulfillment_status', ['returned_refunded', 'return', 'returned']);
            return;
        }

        $query->where('tbl_checkout_history.ch_fulfillment_status', $filter);
    }

    private function supplierOrdersBaseQuery(int $supplierId, int $brandTypeValue)
    {
        return CheckoutHistory::query()
            ->leftJoin('tbl_product as p', 'p.pd_id', '=', 'tbl_checkout_history.ch_product_id')
            ->select([
                'tbl_checkout_history.ch_id',
                'tbl_checkout_history.ch_customer_id',
                'tbl_checkout_history.ch_checkout_id',
                'tbl_checkout_history.ch_status',
                'tbl_checkout_history.ch_approval_status',
                'tbl_checkout_history.ch_approval_notes',
                'tbl_checkout_history.ch_approved_by',
                'tbl_checkout_history.ch_approved_at',
                'tbl_checkout_history.ch_fulfillment_status',
                'tbl_checkout_history.ch_courier',
                'tbl_checkout_history.ch_tracking_no',
                'tbl_checkout_history.ch_shipment_status',
                'tbl_checkout_history.ch_shipment_payload',
                'tbl_checkout_history.ch_shipped_at',
                'tbl_checkout_history.ch_product_name',
                'tbl_checkout_history.ch_product_id',
                'tbl_checkout_history.ch_product_sku',
                'tbl_checkout_history.ch_product_image',
                'tbl_checkout_history.ch_quantity',
                'tbl_checkout_history.ch_amount',
                'tbl_checkout_history.ch_payment_method',
                'tbl_checkout_history.ch_customer_name',
                'tbl_checkout_history.ch_customer_email',
                'tbl_checkout_history.ch_customer_phone',
                'tbl_checkout_history.ch_customer_address',
                'tbl_checkout_history.ch_paid_at',
                'tbl_checkout_history.created_at',
                'tbl_checkout_history.updated_at',
                'p.pd_description as product_description',
            ])
            ->whereNotNull('tbl_checkout_history.ch_product_id')
            ->when($brandTypeValue > 0, function ($builder) use ($supplierId, $brandTypeValue) {
                $builder->where(function ($inner) use ($supplierId, $brandTypeValue) {
                    $inner->where('p.pd_supplier', $supplierId)
                        ->orWhere('p.pd_brand_type', $brandTypeValue);
                });
            }, function ($builder) use ($supplierId) {
                $builder->where('p.pd_supplier', $supplierId);
            });
    }

    private function findScopedOrder(SupplierUser $supplierUser, int $id): ?CheckoutHistory
    {
        $supplierId = (int) $supplierUser->su_supplier;
        $brandTypeValue = $supplierId > 0 ? $this->resolveSupplierBrandType($supplierId) : 0;

        return $this->supplierOrdersBaseQuery($supplierId, $brandTypeValue)
            ->where('tbl_checkout_history.ch_id', $id)
            ->first();
    }

    private function mapOrder($row): array
    {
        return [
            'id' => (int) $row->ch_id,
            'customer_id' => (int) $row->ch_customer_id,
            'checkout_id' => $row->ch_checkout_id,
            'payment_status' => $row->ch_status,
            'approval_status' => $row->ch_approval_status ?? 'pending_approval',
            'approval_notes' => $row->ch_approval_notes,
            'approved_by' => $row->ch_approved_by ? (int) $row->ch_approved_by : null,
            'approved_at' => optional($row->ch_approved_at)->toDateTimeString(),
            'fulfillment_status' => $row->ch_fulfillment_status ?? 'pending',
            'courier' => $row->ch_courier,
            'tracking_no' => $row->ch_tracking_no,
            'shipment_status' => $row->ch_shipment_status,
            'shipment_payload' => is_array($row->ch_shipment_payload) ? $row->ch_shipment_payload : null,
            'shipped_at' => optional($row->ch_shipped_at)->toDateTimeString(),
            'product_name' => $row->ch_product_name ?? ($row->ch_description ?? 'Order Item'),
            'product_description' => $row->product_description ?? null,
            'product_image' => $row->ch_product_image,
            'quantity' => (int) $row->ch_quantity,
            'amount' => (float) $row->ch_amount,
            'payment_method' => $row->ch_payment_method,
            'customer_name' => $row->ch_customer_name,
            'customer_email' => $row->ch_customer_email,
            'customer_phone' => $row->ch_customer_phone,
            'customer_address' => $row->ch_customer_address,
            'paid_at' => optional($row->ch_paid_at)->toDateTimeString(),
            'created_at' => optional($row->created_at)->toDateTimeString(),
            'updated_at' => optional($row->updated_at)->toDateTimeString(),
        ];
    }

    private function normalizeFilter(string $filter): string
    {
        $normalized = strtolower(trim($filter));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return match ($normalized) {
            'topay' => 'to_pay',
            'toship' => 'to_ship',
            'toreceive', 'to_received', 'received' => 'to_receive',
            'return', 'returned', 'returned_refunded' => 'return',
            default => $normalized,
        };
    }

    private function counts(int $supplierId, int $brandTypeValue): array
    {
        $base = $this->supplierOrdersBaseQuery($supplierId, $brandTypeValue);

        return [
            'total' => (int) (clone $base)->count(),
            'to_pay' => (int) (clone $base)->whereIn('tbl_checkout_history.ch_status', ['pending', 'unpaid', 'failed', 'cancelled', 'expired', 'active'])->count(),
            'to_ship' => (int) (clone $base)->whereIn('tbl_checkout_history.ch_fulfillment_status', ['processing', 'packed'])->count(),
            'to_receive' => (int) (clone $base)->whereIn('tbl_checkout_history.ch_fulfillment_status', ['shipped', 'out_for_delivery'])->count(),
            'completed' => (int) (clone $base)->where('tbl_checkout_history.ch_fulfillment_status', 'delivered')->count(),
            'cancelled' => (int) (clone $base)->whereIn('tbl_checkout_history.ch_fulfillment_status', ['cancelled', 'refunded'])->count(),
            'return' => (int) (clone $base)->whereIn('tbl_checkout_history.ch_fulfillment_status', ['returned_refunded', 'return', 'returned'])->count(),
        ];
    }
}
