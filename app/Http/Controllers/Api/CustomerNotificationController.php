<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckoutHistory;
use App\Models\Customer;
use App\Models\CustomerVerificationRequest;
use App\Models\EncashmentRequest;
use Illuminate\Http\Request;

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

        $shippingUpdatesCount = (int) CheckoutHistory::query()
            ->where('ch_customer_id', $customerId)
            ->whereIn('ch_fulfillment_status', ['shipped', 'out_for_delivery', 'delivered'])
            ->where('updated_at', '>=', $now->copy()->subDays(7))
            ->count();

        $encashmentUpdatesCount = (int) EncashmentRequest::query()
            ->where('er_customer_id', $customerId)
            ->whereIn('er_status', ['approved_by_admin', 'released', 'rejected', 'failed'])
            ->where('updated_at', '>=', $now->copy()->subDays(14))
            ->count();

        $kycMeta = $this->resolveKycMeta($customer);
        $kycActionCount = $kycMeta['count'];

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
            ],
            [
                'id' => 'kyc_status',
                'title' => 'KYC Verification',
                'description' => $kycMeta['description'],
                'count' => $kycActionCount,
                'severity' => $kycMeta['severity'],
                'href' => '/profile',
            ],
        ];

        $unreadCount = $shippingUpdatesCount + $encashmentUpdatesCount + $kycActionCount;

        return response()->json([
            'unread_count' => $unreadCount,
            'items' => $items,
            'generated_at' => $now->toDateTimeString(),
        ]);
    }

    private function resolveKycMeta(Customer $customer): array
    {
        $status = (int) ($customer->c_accnt_status ?? 0);
        $lock = (int) ($customer->c_lockstatus ?? 0);

        if ($lock === 1) {
            return [
                'count' => 1,
                'severity' => 'critical',
                'description' => 'Account is blocked. Please contact support.',
            ];
        }

        if ($status === 1) {
            return [
                'count' => 0,
                'severity' => 'success',
                'description' => 'Your account is verified.',
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
                'description' => 'KYC is under review. Wait for admin update.',
            ];
        }

        return [
            'count' => 1,
            'severity' => 'warning',
            'description' => 'KYC not submitted. Complete verification to unlock full features.',
        ];
    }
}
