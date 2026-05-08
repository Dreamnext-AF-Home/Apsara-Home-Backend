<?php

namespace App\Support;

use App\Models\CheckoutHistory;
use App\Models\Customer;
use App\Models\CustomerWalletLedger;
use App\Models\GroupPurchaseBonusAward;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GroupPurchaseBonus
{
    public static function awardForBuyer(Customer $buyer, ?CheckoutHistory $referenceOrder = null, ?int $awardedBy = null): void
    {
        if (!Schema::hasTable('tbl_group_purchase_bonus_awards')) {
            return;
        }

        $earnedPv = max(0, (float) ($referenceOrder?->ch_earned_pv ?? 0));
        if ($earnedPv <= 0) {
            return;
        }

        $uplineChain = self::resolveEligibleUplineChain($buyer, self::maxPaidLevels());
        if ($uplineChain->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($uplineChain, $buyer, $referenceOrder, $awardedBy, $earnedPv) {
            foreach ($uplineChain as $entry) {
                /** @var Customer $upline */
                $upline = $entry['customer'];
                $levelNo = (int) $entry['level'];
                $rate = self::rateForLevel($levelNo);
                if ($rate <= 0) {
                    continue;
                }

                $bonusAmount = round($earnedPv * $rate, 2);
                if ($bonusAmount <= 0) {
                    continue;
                }

                $alreadyAwarded = GroupPurchaseBonusAward::query()
                    ->where('gpba_customer_id', (int) $upline->c_userid)
                    ->where('gpba_reference_order_id', (int) ($referenceOrder?->ch_id ?? 0))
                    ->where('gpba_level_no', $levelNo)
                    ->exists();

                if ($alreadyAwarded) {
                    continue;
                }

                $award = GroupPurchaseBonusAward::create([
                    'gpba_customer_id' => (int) $upline->c_userid,
                    'gpba_source_customer_id' => (int) $buyer->c_userid,
                    'gpba_level_no' => $levelNo,
                    'gpba_reference_order_id' => $referenceOrder?->ch_id ? (int) $referenceOrder->ch_id : null,
                    'gpba_checkout_id' => (string) ($referenceOrder?->ch_checkout_id ?? ''),
                    'gpba_earned_pv' => $earnedPv,
                    'gpba_bonus_rate' => $rate,
                    'gpba_bonus_amount' => $bonusAmount,
                    'gpba_unlocked_max_level' => self::maxPaidLevels(),
                    'gpba_awarded_by' => $awardedBy,
                    'gpba_awarded_at' => now(),
                    'gpba_notes' => sprintf(
                        'Unilevel bonus awarded from compressed level %d downline PV.',
                        $levelNo
                    ),
                ]);

                $alreadyCredited = CustomerWalletLedger::query()
                    ->where('wl_wallet_type', 'cash')
                    ->where('wl_entry_type', 'credit')
                    ->where('wl_source_type', 'group_purchase_bonus')
                    ->where('wl_source_id', (int) $award->gpba_id)
                    ->exists();

                if (!$alreadyCredited) {
                    $upline->c_totalincome = (float) ($upline->c_totalincome ?? 0) + $bonusAmount;
                    $upline->save();

                    CustomerWalletLedger::create([
                        'wl_customer_id' => (int) $upline->c_userid,
                        'wl_wallet_type' => 'cash',
                        'wl_entry_type' => 'credit',
                        'wl_amount' => $bonusAmount,
                        'wl_source_type' => 'group_purchase_bonus',
                        'wl_source_id' => (int) $award->gpba_id,
                        'wl_reference_no' => (string) ($referenceOrder?->ch_checkout_id ?? ('GPB-' . $levelNo)),
                        'wl_notes' => sprintf(
                            'Unilevel bonus credited from compressed level %d downline order.',
                            $levelNo
                        ),
                        'wl_created_by' => $awardedBy,
                    ]);
                }
            }
        });
    }

    public static function maxPaidLevels(): int
    {
        return max(1, min(10, (int) env('UNILEVEL_BONUS_MAX_LEVELS', 10)));
    }

    public static function rateForLevel(int $levelNo): float
    {
        if ($levelNo < 1 || $levelNo > self::maxPaidLevels()) {
            return 0.0;
        }

        $totalRate = max(0, (float) env('UNILEVEL_BONUS_RATE', 0.06));
        $maxLevels = self::maxPaidLevels();

        return $maxLevels > 0 ? $totalRate / $maxLevels : 0.0;
    }

    private static function resolveEligibleUplineChain(Customer $buyer, int $maxPaidLevels = 10)
    {
        $chain = collect();
        $visited = [];
        $currentSponsorId = (int) ($buyer->c_sponsor ?? 0);
        $paidLevel = 1;

        while ($currentSponsorId > 0 && $paidLevel <= $maxPaidLevels && !in_array($currentSponsorId, $visited, true)) {
            $visited[] = $currentSponsorId;
            $customer = Customer::query()->where('c_userid', $currentSponsorId)->first();
            if (!$customer) {
                break;
            }

            $activation = MemberMonthlyActivation::summary($customer);
            if (($activation['status'] ?? 'inactive') === 'active') {
                $chain->push([
                    'level' => $paidLevel,
                    'customer' => $customer,
                ]);
                $paidLevel++;
            }

            $currentSponsorId = (int) ($customer->c_sponsor ?? 0);
        }

        return $chain;
    }
}
