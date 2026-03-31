<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckoutHistory;
use App\Models\Supplier;
use App\Models\SupplierUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupplierOrderController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user instanceof SupplierUser) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $supplierId = (int) ($user->su_supplier ?? 0);
        if ($supplierId <= 0) {
            return response()->json([
                'orders' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 20,
                    'total' => 0,
                    'from' => null,
                    'to' => null,
                ],
                'counts' => $this->countsForSupplier($supplierId, 0),
            ]);
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

        $brandId = $this->resolveSupplierBrandId($supplierId);

        $query = CheckoutHistory::query()
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
                'tbl_checkout_history.ch_shipped_at',
                'tbl_checkout_history.ch_product_name',
                'tbl_checkout_history.ch_product_id',
                'tbl_checkout_history.ch_product_sku',
                'tbl_checkout_history.ch_selected_color',
                'tbl_checkout_history.ch_selected_size',
                'tbl_checkout_history.ch_selected_type',
                'tbl_checkout_history.ch_product_pv',
                'tbl_checkout_history.ch_earned_pv',
                'tbl_checkout_history.ch_pv_posted_at',
                'tbl_checkout_history.ch_product_image',
                'tbl_checkout_history.ch_quantity',
                'tbl_checkout_history.ch_amount',
                'tbl_checkout_history.ch_payment_method',
                'tbl_checkout_history.ch_customer_name',
                'tbl_checkout_history.ch_customer_email',
                'tbl_checkout_history.ch_customer_phone',
                'tbl_checkout_history.ch_customer_address',
                'tbl_checkout_history.ch_paid_at',
                'p.pd_description',
                'tbl_checkout_history.created_at',
                'tbl_checkout_history.updated_at',
            ])
            ->join('tbl_product as p', 'p.pd_id', '=', 'tbl_checkout_history.ch_product_id')
            ->where(function ($q) use ($supplierId, $brandId) {
                $q->where('p.pd_supplier', $supplierId);
                if ($brandId > 0) {
                    $q->orWhere('p.pd_brand_type', $brandId);
                }
            })
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

        $items = collect($paginated->items())->map(function (CheckoutHistory $order) {
            $shipmentPayload = $order->ch_shipment_payload;
            if (is_string($shipmentPayload) && trim($shipmentPayload) !== '') {
                $decodedPayload = json_decode($shipmentPayload, true);
                $shipmentPayload = is_array($decodedPayload) ? $decodedPayload : [];
            }
            if (!is_array($shipmentPayload)) {
                $shipmentPayload = [];
            }

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
                'tracking_no' => $order->ch_tracking_no,
                'shipment_status' => $order->ch_shipment_status,
                'shipment_payload' => !empty($shipmentPayload) ? $shipmentPayload : null,
                'shipped_at' => optional($order->ch_shipped_at)->toDateTimeString(),
                'product_name' => $order->ch_product_name ?? ($order->ch_description ?? 'Order Item'),
                'product_description' => $order->ch_description ?? $order->pd_description,
                'product_id' => $order->ch_product_id ? (int) $order->ch_product_id : null,
                'product_sku' => $order->ch_product_sku,
                'selected_color' => $order->ch_selected_color,
                'selected_size' => $order->ch_selected_size,
                'selected_type' => $order->ch_selected_type,
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
            'counts' => $this->countsForSupplier($supplierId, $brandId),
        ]);
    }

    private function normalizeFilter(string $filter): string
    {
        $normalized = strtolower(trim($filter));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return match ($normalized) {
            'to_pay' => 'to_pay',
            'to_ship' => 'to_ship',
            'to_receive', 'to_received', 'to_recieved' => 'to_receive',
            'return', 'returns', 'returned', 'returned_refunded' => 'return',
            default => $normalized,
        };
    }

    private function applyFilter($query, string $filter): void
    {
        if ($filter === 'all' || $filter === '') {
            return;
        }

        if ($filter === 'to_pay') {
            $query->whereIn('tbl_checkout_history.ch_status', ['pending', 'unpaid', 'failed']);
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

        if ($filter === 'cancelled') {
            $query->whereIn('tbl_checkout_history.ch_fulfillment_status', ['cancelled', 'refunded']);
            return;
        }

        if ($filter === 'completed') {
            $query->where('tbl_checkout_history.ch_fulfillment_status', 'delivered');
            return;
        }

        if ($filter === 'return') {
            $query->whereIn('tbl_checkout_history.ch_fulfillment_status', ['returned_refunded']);
            return;
        }
    }

    private function countsForSupplier(int $supplierId, int $brandId): array
    {
        $base = CheckoutHistory::query()
            ->join('tbl_product as p', 'p.pd_id', '=', 'tbl_checkout_history.ch_product_id')
            ->where(function ($q) use ($supplierId, $brandId) {
                $q->where('p.pd_supplier', $supplierId);
                if ($brandId > 0) {
                    $q->orWhere('p.pd_brand_type', $brandId);
                }
            });

        return [
            'total' => (int) (clone $base)->count(),
            'to_pay' => (int) (clone $base)->whereIn('tbl_checkout_history.ch_status', ['pending', 'unpaid', 'failed'])->count(),
            'to_ship' => (int) (clone $base)->whereIn('tbl_checkout_history.ch_fulfillment_status', ['processing', 'packed'])->count(),
            'to_receive' => (int) (clone $base)->whereIn('tbl_checkout_history.ch_fulfillment_status', ['shipped', 'out_for_delivery'])->count(),
            'cancelled' => (int) (clone $base)->whereIn('tbl_checkout_history.ch_fulfillment_status', ['cancelled', 'refunded'])->count(),
            'completed' => (int) (clone $base)->where('tbl_checkout_history.ch_fulfillment_status', 'delivered')->count(),
            'return' => (int) (clone $base)->whereIn('tbl_checkout_history.ch_fulfillment_status', ['returned_refunded'])->count(),
        ];
    }

    private function resolveSupplierBrandId(int $supplierId): int
    {
        $supplier = Supplier::query()->where('s_id', $supplierId)->first();
        if (! $supplier) {
            return 0;
        }

        $candidates = array_filter([
            $supplier->s_company ?? '',
            $supplier->s_name ?? '',
        ], fn ($value) => trim((string) $value) !== '');

        if (empty($candidates)) {
            return 0;
        }

        $brands = DB::table('tbl_product_brand')->select('pb_id', 'pb_name')->get();
        $bestId = 0;
        $bestLen = 0;

        foreach ($brands as $brand) {
            $brandName = trim((string) ($brand->pb_name ?? ''));
            if ($brandName === '') {
                continue;
            }
            $brandKey = $this->normalizeKey($brandName);
            if ($brandKey === '') {
                continue;
            }

            foreach ($candidates as $candidate) {
                $candidateKey = $this->normalizeKey($candidate);
                if ($candidateKey === '' || $candidateKey !== $brandKey) {
                    continue;
                }
                $len = strlen($brandKey);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $bestId = (int) ($brand->pb_id ?? 0);
                }
            }
        }

        return $bestId;
    }

    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]/', '', $value) ?? '';
        return $value;
    }
}
