<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckoutHistory;
use App\Models\ProductBrand;
use App\Models\SupplierUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class SupplierOrderController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user instanceof SupplierUser) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $supplierId = (int) ($user->su_supplier ?? 0);
        $supplier = $user->supplier;
        $companyName = trim((string) ($supplier?->s_company ?? ''));
        $supplierName = trim((string) ($supplier?->s_name ?? ''));

        $nameCandidates = array_values(array_filter(array_unique([
            mb_strtolower($companyName),
            mb_strtolower($supplierName),
        ])));

        $brandIds = [];
        if (!empty($nameCandidates)) {
            $brandIds = ProductBrand::query()
                ->where(function ($q) use ($nameCandidates) {
                    foreach ($nameCandidates as $name) {
                        $q->orWhereRaw('LOWER(pb_name) = ?', [$name]);
                    }
                })
                ->pluck('pb_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($supplierId <= 0 && empty($brandIds) && $companyName === '' && $supplierName === '') {
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
            ]);
        }

        $perPage = max(1, min(50, (int) $request->query('per_page', 20)));
        $page = max(1, (int) $request->query('page', 1));

        $query = CheckoutHistory::query()
            ->select([
                'tbl_checkout_history.ch_id',
                'tbl_checkout_history.ch_checkout_id',
                'tbl_checkout_history.ch_status',
                'tbl_checkout_history.ch_fulfillment_status',
                'tbl_checkout_history.ch_product_name',
                'tbl_checkout_history.ch_product_sku',
                'tbl_checkout_history.ch_product_image',
                'tbl_checkout_history.ch_quantity',
                'tbl_checkout_history.ch_amount',
                'tbl_checkout_history.ch_payment_method',
                'tbl_checkout_history.ch_customer_name',
                'tbl_checkout_history.ch_paid_at',
                'tbl_checkout_history.ch_description',
                'tbl_checkout_history.created_at',
                'tbl_checkout_history.updated_at',
            ])
            ->where(function ($q) use ($supplierId, $brandIds, $companyName, $supplierName) {
                $needle = strtolower(trim($companyName !== '' ? $companyName : $supplierName));

                $q->whereExists(function ($sub) use ($supplierId, $brandIds, $needle) {
                    $sub->from('tbl_product as p')
                        ->leftJoin('tbl_product_brand as pb', 'pb.pb_id', '=', 'p.pd_brand_type')
                        ->where(function ($match) {
                            $match->whereColumn('p.pd_id', 'tbl_checkout_history.ch_product_id')
                                ->orWhereColumn('p.pd_parent_sku', 'tbl_checkout_history.ch_product_sku')
                                ->orWhereRaw('LOWER(p.pd_name) = LOWER(tbl_checkout_history.ch_product_name)');
                        });

                    if ($supplierId > 0) {
                        $sub->where('p.pd_supplier', $supplierId);
                    }
                    if (!empty($brandIds)) {
                        $sub->whereIn('p.pd_brand_type', $brandIds);
                    }
                    if ($needle !== '') {
                        $like = '%' . $needle . '%';
                        $sub->where(function ($nameMatch) use ($like) {
                            $nameMatch->whereRaw('LOWER(pb.pb_name) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(tbl_checkout_history.ch_product_name) LIKE ?', [$like]);
                        });
                    }
                });
            })
            ->orderByDesc('tbl_checkout_history.ch_paid_at')
            ->orderByDesc('tbl_checkout_history.ch_id');

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        $orders = collect($paginated->items())->map(function ($order) {
            $paidAt = $order->ch_paid_at ? Carbon::parse($order->ch_paid_at)->toDateTimeString() : null;
            $createdAt = $order->created_at ? Carbon::parse($order->created_at)->toDateTimeString() : null;
            $updatedAt = $order->updated_at ? Carbon::parse($order->updated_at)->toDateTimeString() : null;

            return [
                'id' => (int) $order->ch_id,
                'checkout_id' => (string) ($order->ch_checkout_id ?? ''),
                'payment_status' => (string) ($order->ch_status ?? ''),
                'fulfillment_status' => (string) ($order->ch_fulfillment_status ?? 'pending'),
                'product_name' => (string) ($order->ch_product_name ?? ($order->ch_description ?? 'Order Item')),
                'product_image' => (string) ($order->ch_product_image ?? ''),
                'quantity' => (int) ($order->ch_quantity ?? 1),
                'amount' => (float) ($order->ch_amount ?? 0),
                'payment_method' => (string) ($order->ch_payment_method ?? ''),
                'customer_name' => (string) ($order->ch_customer_name ?? ''),
                'paid_at' => $paidAt,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ];
        })->values();

        return response()->json([
            'orders' => $orders,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'from' => $paginated->firstItem(),
                'to' => $paginated->lastItem(),
            ],
        ]);
    }
}
