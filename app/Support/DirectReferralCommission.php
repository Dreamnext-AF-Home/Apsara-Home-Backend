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

        $existing = ReferralEarning::query()
            ->where('re_order_id', (int) $order->ch_id)
            ->where('re_referrer_customer_id', $referrerCustomerId)
            ->exists();
        if ($existing) {
            return;
        }

        $basisAmount = max(0, (float) ($order->ch_commission_basis_amount ?? 0));
        $rate = max(0, (float) env('DIRECT_REFERRAL_COMMISSION_RATE', 1));
        $amount = round($basisAmount * $rate, 2);
        if ($amount <= 0) {
            return;
        }

        ReferralEarning::create([
            're_order_id' => (int) $order->ch_id,
            're_checkout_id' => (string) ($order->ch_checkout_id ?? ''),
            're_buyer_customer_id' => $buyerCustomerId > 0 ? $buyerCustomerId : null,
            're_referrer_customer_id' => $referrerCustomerId,
            're_product_id' => $order->ch_product_id ? (int) $order->ch_product_id : null,
            're_product_sku' => (string) ($order->ch_product_sku ?? ''),
            're_quantity' => max(1, (int) ($order->ch_quantity ?? 1)),
            're_order_amount' => (float) ($order->ch_amount ?? 0),
            're_commission_basis_amount' => $basisAmount,
            're_amount' => $amount,
            're_status' => 'pending',
            're_source_type' => $sourceType ?: null,
            're_reference_no' => (string) ($order->ch_checkout_id ?? ''),
            're_notes' => 'Direct referral commission created on paid order.',
        ]);
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

                $alreadyCredited = CustomerWalletLedger::query()
                    ->where('wl_wallet_type', 'cash')
                    ->where('wl_entry_type', 'credit')
                    ->where('wl_source_type', 'referral_earning')
                    ->where('wl_source_id', (int) $earning->re_id)
                    ->exists();

                if (!$alreadyCredited) {
                    $customer->c_totalincome = (float) ($customer->c_totalincome ?? 0) + (float) $earning->re_amount;
                    $customer->save();

                    CustomerWalletLedger::create([
                        'wl_customer_id' => (int) $customer->c_userid,
                        'wl_wallet_type' => 'cash',
                        'wl_entry_type' => 'credit',
                        'wl_amount' => (float) $earning->re_amount,
                        'wl_source_type' => 'referral_earning',
                        'wl_source_id' => (int) $earning->re_id,
                        'wl_reference_no' => (string) ($earning->re_reference_no ?? $order->ch_checkout_id ?? ''),
                        'wl_notes' => 'Direct referral commission released on delivered order.',
                        'wl_created_by' => $releasedBy,
                    ]);
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
