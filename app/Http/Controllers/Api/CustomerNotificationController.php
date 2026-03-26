<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckoutHistory;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\CustomerVerificationRequest;
use App\Models\EncashmentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                'title' => 'KYC Verification',
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
                return [
                    'id' => 'customer_notification:' . (int) $notification->cn_id,
                    'title' => (string) ($notification->cn_title ?? 'Account Update'),
                    'description' => (string) ($notification->cn_message ?? ''),
                    'count' => 1,
                    'severity' => (string) ($notification->cn_severity ?? 'info'),
                    'href' => (string) ($notification->cn_href ?? '/profile'),
                    'latest_at' => optional($notification->cn_created_at)->toDateTimeString(),
                ];
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
                'description' => 'KYC is under review. Wait for admin update.',
                'latest_at' => optional($latestKyc?->updated_at ?? $latestKyc?->created_at)?->toDateTimeString(),
            ];
        }

        return [
            'count' => 1,
            'severity' => 'warning',
            'description' => 'KYC not submitted. Complete verification to unlock full features.',
            'latest_at' => null,
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
}
