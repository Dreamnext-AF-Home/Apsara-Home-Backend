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

        $uplineChain = self::resolveUplineChain($buyer, 10);
        if ($uplineChain->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($uplineChain, $buyer, $referenceOrder, $awardedBy, $earnedPv) {
            foreach ($uplineChain as $entry) {
                /** @var Customer $upline */
                $upline = $entry['customer'];
                $levelNo = (int) $entry['level'];
                $unlockedMaxLevel = self::unlockedMaxLevel($upline);

                if ($levelNo > $unlockedMaxLevel) {
                    continue;
                }

                $activation = MemberMonthlyActivation::summary($upline);
                if (($activation['status'] ?? 'inactive') !== 'active') {
                    continue;
                }

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
                    'gpba_unlocked_max_level' => $unlockedMaxLevel,
                    'gpba_awarded_by' => $awardedBy,
                    'gpba_awarded_at' => now(),
                    'gpba_notes' => sprintf(
                        'Group purchase bonus awarded from level %d downline PV.',
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
                            'Group purchase bonus credited from level %d downline order.',
                            $levelNo
                        ),
                        'wl_created_by' => $awardedBy,
                    ]);
                }
            }
        });
    }

    public static function unlockedMaxLevel(Customer $member): int
    {
        $directs = Customer::query()
            ->where('c_sponsor', (int) $member->c_userid)
            ->get(['c_userid', 'c_gpv']);

        $directsAt100 = $directs->filter(fn (Customer $row) => (float) ($row->c_gpv ?? 0) >= 100)->count();
        $directsAt400 = $directs->filter(fn (Customer $row) => (float) ($row->c_gpv ?? 0) >= 400)->count();

        if ($directsAt400 >= 3) {
            return 10;
        }

        if ($directsAt100 >= 3) {
            return 9;
        }

        if ($directsAt100 >= 2) {
            return 8;
        }

        return 7;
    }

    public static function rateForLevel(int $levelNo): float
    {
        $rates = self::rates();
        if ($levelNo < 1 || $levelNo > 10) {
            return 0.0;
        }

        return max(0, (float) ($rates[$levelNo - 1] ?? 0));
    }

    private static function rates(): array
    {
        $raw = trim((string) env('GROUP_PURCHASE_BONUS_RATES', '0,0,0,0,0,0,0,0,0,0'));
        $parts = array_map('trim', explode(',', $raw));
        $parts = array_pad($parts, 10, '0');

        return array_map(fn ($value) => max(0, (float) $value), array_slice($parts, 0, 10));
    }

    private static function resolveUplineChain(Customer $buyer, int $maxLevels = 10)
    {
        $chain = collect();
        $visited = [];
        $currentSponsorId = (int) ($buyer->c_sponsor ?? 0);
        $level = 1;

        while ($currentSponsorId > 0 && $level <= $maxLevels && !in_array($currentSponsorId, $visited, true)) {
            $visited[] = $currentSponsorId;
            $customer = Customer::query()->where('c_userid', $currentSponsorId)->first();
            if (!$customer) {
                break;
            }

            $chain->push([
                'level' => $level,
                'customer' => $customer,
            ]);

            $currentSponsorId = (int) ($customer->c_sponsor ?? 0);
            $level++;
        }

        return $chain;
    }
}
