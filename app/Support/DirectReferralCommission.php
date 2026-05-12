<?php

namespace App\Support;

use App\Models\CheckoutHistory;
use App\Models\Customer;
use App\Models\CustomerWalletLedger;
use App\Models\ReferralEarning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DirectReferralCommission
{
    public static function releasePendingForDeliveredOrders(?int $referrerCustomerId = null): void
    {
        if (!Schema::hasTable('tbl_referral_earnings') || !Schema::hasTable('tbl_checkout_history')) {
            return;
        }

        $query = ReferralEarning::query()
            ->where('re_status', 'pending')
            ->whereIn('re_order_id', function ($builder) {
                $builder
                    ->select('ch_id')
                    ->from('tbl_checkout_history')
                    ->where(function ($statusQuery) {
                        $statusQuery
                            ->where('ch_fulfillment_status', 'delivered')
                            ->orWhere('ch_fulfillment_status', 'completed')
                            ->orWhere('ch_shipment_status', 'delivered');
                    });
            });

        if ((int) $referrerCustomerId > 0) {
            $query->where('re_referrer_customer_id', (int) $referrerCustomerId);
        }

        $query->get()->each(function (ReferralEarning $earning) {
            $order = CheckoutHistory::query()->find((int) $earning->re_order_id);
            if ($order) {
                self::releaseAvailableForOrder($order, null);
            }
        });
    }

    public static function createPendingIfEligible(CheckoutHistory $order, ?int $referrerCustomerId, ?string $sourceType = null): void
    {
        if (!Schema::hasTable('tbl_referral_earnings')) {
            return;
        }

        $referrerCustomerId = (int) $referrerCustomerId;
        $buyerCustomerId = (int) ($order->ch_customer_id ?? 0);
        if ($referrerCustomerId <= 0 || $referrerCustomerId === $buyerCustomerId) {
            return;
        }

        // Only trigger on the buyer's first purchase with this referrer
        $firstPurchaseAlreadyRecorded = ReferralEarning::query()
            ->where('re_buyer_customer_id', $buyerCustomerId)
            ->where('re_referrer_customer_id', $referrerCustomerId)
            ->whereNotIn('re_status', ['cancelled'])
            ->exists();
        if ($firstPurchaseAlreadyRecorded) {
            return;
        }

        // Guard: don't double-create for the same order
        $existing = ReferralEarning::query()
            ->where('re_order_id', (int) $order->ch_id)
            ->where('re_referrer_customer_id', $referrerCustomerId)
            ->exists();
        if ($existing) {
            return;
        }

        $basisAmount = max(0, (float) ($order->ch_commission_basis_amount ?? 0));
        $rate = max(0, (float) env('DIRECT_REFERRAL_COMMISSION_RATE', 1));
        $totalAmount = round($basisAmount * $rate, 2);
        if ($totalAmount <= 0) {
            return;
        }

        // 50% cash + 50% e-GC per PDF spec
        $cashAmount = round($totalAmount * 0.5, 2);
        $egcAmount = round($totalAmount - $cashAmount, 2);

        $shared = [
            're_order_id' => (int) $order->ch_id,
            're_checkout_id' => (string) ($order->ch_checkout_id ?? ''),
            're_buyer_customer_id' => $buyerCustomerId > 0 ? $buyerCustomerId : null,
            're_referrer_customer_id' => $referrerCustomerId,
            're_product_id' => $order->ch_product_id ? (int) $order->ch_product_id : null,
            're_product_sku' => (string) ($order->ch_product_sku ?? ''),
            're_quantity' => max(1, (int) ($order->ch_quantity ?? 1)),
            're_order_amount' => (float) ($order->ch_amount ?? 0),
            're_commission_basis_amount' => $basisAmount,
            're_status' => 'pending',
            're_source_type' => $sourceType ?: null,
            're_reference_no' => (string) ($order->ch_checkout_id ?? ''),
        ];

        ReferralEarning::create(array_merge($shared, [
            're_wallet_type' => 'cash',
            're_amount' => $cashAmount,
            're_notes' => 'Direct referral commission (cash 50%) — first purchase only.',
        ]));

        ReferralEarning::create(array_merge($shared, [
            're_wallet_type' => 'egc',
            're_amount' => $egcAmount,
            're_notes' => 'Direct referral commission (e-GC 50%) — first purchase only.',
        ]));
    }

    public static function releaseAvailableForOrder(CheckoutHistory $order, ?int $releasedBy = null): void
    {
        if (!Schema::hasTable('tbl_referral_earnings')) {
            return;
        }

        DB::transaction(function () use ($order, $releasedBy) {
            $earnings = ReferralEarning::query()
                ->where('re_order_id', (int) $order->ch_id)
                ->where('re_status', 'pending')
                ->lockForUpdate()
                ->get();

            foreach ($earnings as $earning) {
                $customer = Customer::query()
                    ->where('c_userid', (int) $earning->re_referrer_customer_id)
                    ->lockForUpdate()
                    ->first();

                if (!$customer) {
                    continue;
                }

                $walletType = (string) ($earning->re_wallet_type ?? 'cash');

                $alreadyCredited = CustomerWalletLedger::query()
                    ->where('wl_wallet_type', $walletType)
                    ->where('wl_entry_type', 'credit')
                    ->where('wl_source_type', 'referral_earning')
                    ->where('wl_source_id', (int) $earning->re_id)
                    ->exists();

                if (!$alreadyCredited) {
                    $customer->c_totalincome = (float) ($customer->c_totalincome ?? 0) + (float) $earning->re_amount;
                    $customer->save();

                    CustomerWalletLedger::create([
                        'wl_customer_id' => (int) $customer->c_userid,
                        'wl_wallet_type' => $walletType,
                        'wl_entry_type' => 'credit',
                        'wl_amount' => (float) $earning->re_amount,
                        'wl_source_type' => 'referral_earning',
                        'wl_source_id' => (int) $earning->re_id,
                        'wl_reference_no' => (string) ($earning->re_reference_no ?? $order->ch_checkout_id ?? ''),
                        'wl_notes' => sprintf(
                            'Direct referral commission (%s) released on delivered order.',
                            $walletType === 'egc' ? 'e-GC 50%' : 'cash 50%'
                        ),
                        'wl_created_by' => $releasedBy,
                    ]);

                    CustomerBonusNotification::notify(
                        $customer,
                        'direct_referral_commission',
                        'Direct referral commission received',
                        sprintf(
                            'You received PHP %s from a referred order.',
                            number_format((float) $earning->re_amount, 2)
                        ),
                        'referral_earning',
                        (int) $earning->re_id,
                        [
                            'buyer_customer_id' => $earning->re_buyer_customer_id ? (int) $earning->re_buyer_customer_id : null,
                            'order_id' => (int) $order->ch_id,
                            'checkout_id' => (string) ($order->ch_checkout_id ?? ''),
                            'commission_basis_amount' => (float) $earning->re_commission_basis_amount,
                            'bonus_amount' => (float) $earning->re_amount,
                        ]
                    );
                }

                $earning->re_status = 'available';
                $earning->re_available_at = now();
                $earning->re_released_by = $releasedBy;
                $earning->re_released_at = now();
                $earning->re_notes = 'Direct referral commission released and available for encashment.';
                $earning->save();
            }
        });
    }

    public static function cancelPendingForOrder(CheckoutHistory $order, ?int $cancelledBy = null, ?string $reason = null): void
    {
        if (!Schema::hasTable('tbl_referral_earnings')) {
            return;
        }

        ReferralEarning::query()
            ->where('re_order_id', (int) $order->ch_id)
            ->where('re_status', 'pending')
            ->update([
                're_status' => 'cancelled',
                're_cancelled_by' => $cancelledBy,
                're_cancelled_at' => now(),
                're_notes' => $reason ?: 'Direct referral commission cancelled before release.',
                'updated_at' => now(),
            ]);
    }
}
