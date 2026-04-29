<?php

namespace App\Support;

use App\Models\CheckoutHistory;
use App\Models\Customer;
use App\Models\CustomerWalletLedger;
use Illuminate\Support\Facades\DB;

class OrderPvPosting
{
    public static function postIfNeeded(CheckoutHistory $order, ?int $actorAdminId = null): bool
    {
        return (bool) DB::transaction(function () use ($order, $actorAdminId) {
            /** @var CheckoutHistory|null $lockedOrder */
            $lockedOrder = CheckoutHistory::query()
                ->where('ch_id', (int) $order->ch_id)
                ->lockForUpdate()
                ->first();

            if (!$lockedOrder) {
                return false;
            }

            $earnedPv = (float) ($lockedOrder->ch_earned_pv ?? 0);
            if ($earnedPv <= 0 || $lockedOrder->ch_pv_posted_at) {
                return false;
            }

            $alreadyPosted = CustomerWalletLedger::query()
                ->where('wl_wallet_type', 'pv')
                ->where('wl_entry_type', 'credit')
                ->where('wl_source_type', 'order')
                ->where('wl_source_id', (int) $lockedOrder->ch_id)
                ->exists();

            if ($alreadyPosted) {
                $lockedOrder->ch_pv_posted_at = now();
                $lockedOrder->save();

                return true;
            }

            $customer = self::resolveRecipient($lockedOrder);
            if (!$customer) {
                return false;
            }

            $customer->c_gpv = (float) ($customer->c_gpv ?? 0) + $earnedPv;
            $customer->save();

            CustomerWalletLedger::create([
                'wl_customer_id' => (int) $customer->c_userid,
                'wl_wallet_type' => 'pv',
                'wl_entry_type' => 'credit',
                'wl_amount' => $earnedPv,
                'wl_source_type' => 'order',
                'wl_source_id' => (int) $lockedOrder->ch_id,
                'wl_reference_no' => $lockedOrder->ch_checkout_id,
                'wl_notes' => self::buildPostingNote($lockedOrder),
                'wl_created_by' => $actorAdminId,
            ]);

            $lockedOrder->ch_pv_posted_at = now();
            $lockedOrder->save();

            DirectAffiliatePerformanceBonus::awardEligibleMilestonesForBuyer($customer, $lockedOrder, $actorAdminId ?? 0);
            GroupPurchaseBonus::awardForBuyer($customer, $lockedOrder, $actorAdminId ?? 0);
            TierEvaluator::evaluate($customer);

            if ((int) ($customer->c_sponsor ?? 0) > 0) {
                $sponsor = Customer::query()->where('c_userid', (int) $customer->c_sponsor)->first();
                if ($sponsor) {
                    TierEvaluator::evaluate($sponsor);
                }
            }

            return true;
        });
    }

    private static function resolveRecipient(CheckoutHistory $order): ?Customer
    {
        $sourceSlug = trim((string) ($order->ch_source_slug ?? ''));
        $referrerCustomerId = (int) ($order->ch_referrer_customer_id ?? 0);

        if ($sourceSlug !== '' && $referrerCustomerId > 0) {
            $referrer = Customer::query()->where('c_userid', $referrerCustomerId)->lockForUpdate()->first();
            if ($referrer) {
                return $referrer;
            }
        }

        $buyerCustomerId = (int) ($order->ch_customer_id ?? 0);
        if ($buyerCustomerId <= 0) {
            return null;
        }

        return Customer::query()->where('c_userid', $buyerCustomerId)->lockForUpdate()->first();
    }

    private static function buildPostingNote(CheckoutHistory $order): string
    {
        $sourceSlug = trim((string) ($order->ch_source_slug ?? ''));
        $referrerCustomerId = (int) ($order->ch_referrer_customer_id ?? 0);

        if ($sourceSlug !== '' && $referrerCustomerId > 0) {
            return 'PV credit posted on order approval to the partner storefront referral account.';
        }

        return 'PV credit posted on order approval.';
    }
}
