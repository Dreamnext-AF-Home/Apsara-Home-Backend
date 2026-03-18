<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\CheckoutHistory;
use App\Services\Shipping\JntShippingService;
use Illuminate\Http\Request;
use RuntimeException;

class JntShippingController extends Controller
{
    public function __construct(private readonly JntShippingService $jntShippingService)
    {
    }

    public function bookForOrder(Request $request, int $id)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!$this->canManageShipping($admin)) {
            return response()->json(['message' => 'Forbidden: shipping access is limited.'], 403);
        }

        $validated = $request->validate([
            'payload' => 'nullable|array',
        ]);

        $order = CheckoutHistory::query()->where('ch_id', $id)->firstOrFail();
        $shipmentPayload = array_merge($this->payloadFromOrder($order), $validated['payload'] ?? []);

        try {
            $response = $this->jntShippingService->bookShipment($shipmentPayload);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => 'Failed to book J&T shipment.',
                'error' => $e->getMessage(),
            ], 422);
        }

        $trackingNo = $this->extractTrackingNo($response);
        $status = $this->extractStatus($response);

        $order->ch_courier = 'jnt';
        $order->ch_tracking_no = $trackingNo ?: $order->ch_tracking_no;
        $order->ch_shipment_status = $status ?: $order->ch_shipment_status;
        $order->ch_shipment_payload = $response;
        if ($trackingNo && !$order->ch_shipped_at) {
            $order->ch_shipped_at = now();
        }
        $order->save();

        return response()->json([
            'message' => 'J&T shipment booked successfully.',
            'order_id' => (int) $order->ch_id,
            'tracking_no' => $order->ch_tracking_no,
            'shipment_status' => $order->ch_shipment_status,
            'payload' => $response,
        ]);
    }

    public function trackByOrder(Request $request, int $id)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!$this->canManageShipping($admin)) {
            return response()->json(['message' => 'Forbidden: shipping access is limited.'], 403);
        }

        $order = CheckoutHistory::query()->where('ch_id', $id)->firstOrFail();
        $trackingNo = trim((string) ($order->ch_tracking_no ?? ''));
        if ($trackingNo === '') {
            return response()->json(['message' => 'Order has no tracking number yet.'], 422);
        }

        return $this->trackByTrackingNo($request, $trackingNo, $order);
    }

    public function trackByTrackingNo(Request $request, string $trackingNo, ?CheckoutHistory $order = null)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        if (!$this->canManageShipping($admin)) {
            return response()->json(['message' => 'Forbidden: shipping access is limited.'], 403);
        }

        try {
            $response = $this->jntShippingService->trackShipment($trackingNo);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => 'Failed to fetch J&T tracking.',
                'error' => $e->getMessage(),
            ], 422);
        }

        if ($order) {
            $status = $this->extractStatus($response);
            $order->ch_courier = 'jnt';
            $order->ch_shipment_status = $status ?: $order->ch_shipment_status;
            $order->ch_shipment_payload = $response;
            $order->save();
        }

        return response()->json([
            'tracking_no' => $trackingNo,
            'shipment_status' => $this->extractStatus($response),
            'payload' => $response,
        ]);
    }

    private function payloadFromOrder(CheckoutHistory $order): array
    {
        $quantity = max(1, (int) ($order->ch_quantity ?? 1));
        $unitValue = $quantity > 0 ? round(((float) ($order->ch_amount ?? 0)) / $quantity, 2) : (float) ($order->ch_amount ?? 0);

        return [
            'reference_no' => (string) ($order->ch_checkout_id ?? ''),
            'recipient_name' => (string) ($order->ch_customer_name ?? ''),
            'recipient_phone' => (string) ($order->ch_customer_phone ?? ''),
            'recipient_email' => (string) ($order->ch_customer_email ?? ''),
            'recipient_address' => (string) ($order->ch_customer_address ?? ''),
            'declared_value' => (float) ($order->ch_amount ?? 0),
            'remark' => (string) ($order->ch_payment_method ?? ''),
            'items' => [[
                'name' => (string) ($order->ch_product_name ?? 'Order Item'),
                'quantity' => $quantity,
                'item_value' => $unitValue,
                'desc' => (string) ($order->ch_product_name ?? 'Order Item'),
            ]],
        ];
    }

    private function extractTrackingNo(array $response): ?string
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

    private function extractStatus(array $response): ?string
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

    private function canManageShipping(Admin $admin): bool
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
}
