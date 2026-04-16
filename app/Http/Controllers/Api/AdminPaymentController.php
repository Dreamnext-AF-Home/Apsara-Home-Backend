<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\CheckoutHistory;
use App\Models\EncashmentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminPaymentController extends Controller
{
    public function overview(Request $request)
    {
        $admin = $this->resolveAdmin($request);
        if (!$admin) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $now = now('Asia/Manila');
        $todayStart = $now->copy()->startOfDay()->utc();
        $todayEnd = $now->copy()->endOfDay()->utc();
        $paidStatuses = ['paid', 'succeeded', 'success'];
        $pendingStatuses = ['pending', 'active', 'unpaid'];
        $failedStatuses = ['failed', 'cancelled', 'expired'];

        $baseOrders = CheckoutHistory::query();

        $successfulPaymentsCount = (clone $baseOrders)
            ->whereIn('ch_status', $paidStatuses)
            ->count();

        $pendingPaymentsCount = (clone $baseOrders)
            ->whereIn('ch_status', $pendingStatuses)
            ->count();

        $failedPaymentsCount = (clone $baseOrders)
            ->whereIn('ch_status', $failedStatuses)
            ->count();

        $todayPaidAmount = (float) (clone $baseOrders)
            ->whereIn('ch_status', $paidStatuses)
            ->whereBetween('ch_paid_at', [$todayStart, $todayEnd])
            ->sum('ch_amount');

        $todayPaidCount = (clone $baseOrders)
            ->whereIn('ch_status', $paidStatuses)
            ->whereBetween('ch_paid_at', [$todayStart, $todayEnd])
            ->count();

        $grossCollectedAmount = (float) (clone $baseOrders)
            ->whereIn('ch_status', $paidStatuses)
            ->sum('ch_amount');

        $paymentMethodRows = (clone $baseOrders)
            ->selectRaw('LOWER(COALESCE(ch_payment_method, ?)) as method_key, COUNT(*) as tx_count, COALESCE(SUM(ch_amount), 0) as total_amount', ['unknown'])
            ->whereIn('ch_status', $paidStatuses)
            ->groupBy('method_key')
            ->orderByDesc('tx_count')
            ->get();

        $recentTransactions = CheckoutHistory::query()
            ->orderByDesc(DB::raw('COALESCE(ch_paid_at, created_at)'))
            ->limit(8)
            ->get([
                'ch_id',
                'ch_checkout_id',
                'ch_payment_intent_id',
                'ch_status',
                'ch_amount',
                'ch_payment_method',
                'ch_customer_name',
                'ch_customer_email',
                'ch_product_name',
                'ch_paid_at',
                'created_at',
            ])
            ->map(function (CheckoutHistory $row): array {
                return [
                    'id' => (int) $row->ch_id,
                    'checkout_id' => (string) ($row->ch_checkout_id ?? ''),
                    'payment_intent_id' => $row->ch_payment_intent_id ?: null,
                    'status' => (string) ($row->ch_status ?? 'pending'),
                    'amount' => (float) ($row->ch_amount ?? 0),
                    'payment_method' => $this->formatPaymentMethod((string) ($row->ch_payment_method ?? '')),
                    'customer_name' => $row->ch_customer_name ?: 'Customer',
                    'customer_email' => $row->ch_customer_email ?: null,
                    'product_name' => $row->ch_product_name ?: null,
                    'paid_at' => optional($row->ch_paid_at)->toDateTimeString(),
                    'created_at' => optional($row->created_at)->toDateTimeString(),
                ];
            })
            ->values();

        $voucherSummary = [
            'available' => Schema::hasTable('tbl_affiliate_voucher_issuances'),
            'total_issued' => 0,
            'active' => 0,
            'redeemed' => 0,
            'expired' => 0,
            'issued_value' => 0.0,
            'reserved_value' => 0.0,
        ];

        $recentVouchers = collect();

        if (Schema::hasTable('tbl_affiliate_voucher_issuances')) {
            $voucherBase = DB::table('tbl_affiliate_voucher_issuances');

            $voucherSummary = [
                'available' => true,
                'total_issued' => (int) (clone $voucherBase)->count(),
                'active' => (int) (clone $voucherBase)
                    ->where('avi_status', 'active')
                    ->where(function ($query) use ($now) {
                        $query->whereNull('avi_expires_at')
                            ->orWhere('avi_expires_at', '>=', $now);
                    })
                    ->count(),
                'redeemed' => (int) (clone $voucherBase)->where('avi_status', 'redeemed')->count(),
                'expired' => (int) (clone $voucherBase)
                    ->where(function ($query) use ($now) {
                        $query->where('avi_status', 'expired')
                            ->orWhere(function ($nested) use ($now) {
                                $nested->where('avi_status', 'active')
                                    ->whereNotNull('avi_expires_at')
                                    ->where('avi_expires_at', '<', $now);
                            });
                    })
                    ->count(),
                'issued_value' => round((float) (clone $voucherBase)->sum('avi_amount'), 2),
                'reserved_value' => round((float) (clone $voucherBase)
                    ->where('avi_status', 'active')
                    ->sum(DB::raw('avi_amount * COALESCE(avi_max_uses, 1)')), 2),
            ];

            $recentVouchers = DB::table('tbl_affiliate_voucher_issuances as vouchers')
                ->leftJoin('tbl_customer as issuer', 'issuer.c_userid', '=', 'vouchers.avi_customer_id')
                ->leftJoin('tbl_customer as redeemer', 'redeemer.c_userid', '=', 'vouchers.avi_redeemed_by_customer_id')
                ->orderByDesc('vouchers.created_at')
                ->limit(8)
                ->get([
                    'vouchers.avi_id',
                    'vouchers.avi_code',
                    'vouchers.avi_amount',
                    'vouchers.avi_status',
                    'vouchers.avi_used_count',
                    'vouchers.avi_max_uses',
                    'vouchers.avi_expires_at',
                    'vouchers.avi_redeemed_at',
                    'vouchers.created_at',
                    'issuer.c_username as issuer_username',
                    'issuer.c_email as issuer_email',
                    'redeemer.c_username as redeemer_username',
                ])
                ->map(function ($row): array {
                    return [
                        'id' => (int) $row->avi_id,
                        'code' => (string) $row->avi_code,
                        'amount' => (float) $row->avi_amount,
                        'status' => $this->normalizeVoucherStatus((string) ($row->avi_status ?? ''), $row->avi_expires_at),
                        'used_count' => $row->avi_used_count !== null ? (int) $row->avi_used_count : 0,
                        'max_uses' => $row->avi_max_uses !== null ? (int) $row->avi_max_uses : null,
                        'expires_at' => $row->avi_expires_at,
                        'redeemed_at' => $row->avi_redeemed_at,
                        'created_at' => $row->created_at,
                        'issuer_name' => $row->issuer_username ?: 'Affiliate',
                        'issuer_email' => $row->issuer_email ?: null,
                        'redeemer_name' => $row->redeemer_username ?: null,
                    ];
                })
                ->values();
        }

        $encashmentSummary = [
            'total_requests' => (int) EncashmentRequest::query()->count(),
            'pending_requests' => (int) EncashmentRequest::query()
                ->whereIn('er_status', ['pending', 'approved_by_admin', 'on_hold'])
                ->count(),
            'released_requests' => (int) EncashmentRequest::query()
                ->where('er_status', 'released')
                ->count(),
            'released_amount' => round((float) EncashmentRequest::query()
                ->where('er_status', 'released')
                ->sum('er_amount'), 2),
        ];

        return response()->json([
            'summary' => [
                'today_paid_amount' => round($todayPaidAmount, 2),
                'today_paid_count' => $todayPaidCount,
                'successful_payments_count' => $successfulPaymentsCount,
                'pending_payments_count' => $pendingPaymentsCount,
                'failed_payments_count' => $failedPaymentsCount,
                'gross_collected_amount' => round($grossCollectedAmount, 2),
            ],
            'payment_methods' => $paymentMethodRows->map(function ($row): array {
                return [
                    'key' => (string) $row->method_key,
                    'label' => $this->formatPaymentMethod((string) $row->method_key),
                    'count' => (int) $row->tx_count,
                    'amount' => round((float) $row->total_amount, 2),
                ];
            })->values(),
            'recent_transactions' => $recentTransactions,
            'voucher_summary' => $voucherSummary,
            'recent_vouchers' => $recentVouchers,
            'encashment_summary' => $encashmentSummary,
        ]);
    }

    private function resolveAdmin(Request $request): ?Admin
    {
        $user = $request->user();
        return $user instanceof Admin ? $user : null;
    }

    private function formatPaymentMethod(string $method): string
    {
        return match (strtolower(trim($method))) {
            'gcash' => 'GCash',
            'maya', 'paymaya' => 'Maya',
            'card' => 'Credit / Debit Card',
            'online_banking' => 'Online Banking',
            'unknown', '' => 'Unknown',
            default => ucfirst(str_replace('_', ' ', $method)),
        };
    }

    private function normalizeVoucherStatus(string $status, mixed $expiresAt): string
    {
        $normalized = strtolower(trim($status));
        if ($normalized === 'active' && $expiresAt) {
            try {
                if (now('Asia/Manila')->gt(\Illuminate\Support\Carbon::parse((string) $expiresAt, 'Asia/Manila'))) {
                    return 'expired';
                }
            } catch (\Throwable) {
                // Keep stored status when the expiry value is malformed.
            }
        }

        return $normalized !== '' ? $normalized : 'unknown';
    }
}
