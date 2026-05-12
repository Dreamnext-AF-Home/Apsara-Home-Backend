<?php

namespace App\Support;

use App\Models\CheckoutHistory;
use App\Models\Customer;
use App\Models\CustomerWalletLedger;
use App\Models\DirectAffiliatePerformanceBonusAward;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DirectAffiliatePerformanceBonus
{
    public static function thresholdPv(): float
    {
        return max(1, (float) env('DIRECT_AFFILIATE_PERFORMANCE_THRESHOLD_PV', 50000));
    }

    public static function bonusAmount(): float
    {
        return max(0, (float) env('DIRECT_AFFILIATE_PERFORMANCE_BONUS_AMOUNT', 5000));
    }

    public static function awardEligibleMilestonesForBuyer(Customer $buyer, ?CheckoutHistory $referenceOrder = null, ?int $awardedBy = null): void
    {
        if (!Schema::hasTable('tbl_direct_affiliate_performance_bonus_awards')) {
            return;
        }

        $sponsorId = (int) ($buyer->c_sponsor ?? 0);
        if ($sponsorId <= 0) {
            return;
        }

        DB::transaction(function () use ($sponsorId, $referenceOrder, $awardedBy) {
            $sponsor = Customer::query()
                ->where('c_userid', $sponsorId)
                ->lockForUpdate()
                ->first();

            if (!$sponsor) {
                return;
            }

            $activation = MemberMonthlyActivation::summary($sponsor);
            if (($activation['status'] ?? 'inactive') !== 'active') {
                return;
            }

            // Current month PV only — resets on the 1st per PDF spec
            $tz = 'Asia/Manila';
            $monthStart = now($tz)->startOfMonth();
            $monthEnd = now($tz)->endOfMonth();

            $directIds = Customer::query()
                ->where('c_sponsor', (int) $sponsor->c_userid)
                ->pluck('c_userid');

            $directCount = $directIds->count();
            $directTotalPv = $directIds->isEmpty() ? 0.0 : (float) CheckoutHistory::query()
                ->whereIn('ch_customer_id', $directIds)
                ->where('ch_earned_pv', '>', 0)
                ->whereNotNull('ch_pv_posted_at')
                ->whereNotIn('ch_status', ['failed', 'cancelled', 'expired'])
                ->whereBetween('ch_pv_posted_at', [$monthStart->toDateTimeString(), $monthEnd->toDateTimeString()])
                ->sum('ch_earned_pv');

            $thresholdPv = self::thresholdPv();
            $bonusAmount = self::bonusAmount();
            $qualifiedMilestones = (int) floor($directTotalPv / $thresholdPv);

            if ($qualifiedMilestones <= 0 || $bonusAmount <= 0) {
                return;
            }

            // Only look at milestones awarded THIS month — resets on the 1st
            $alreadyAwardedMilestones = DirectAffiliatePerformanceBonusAward::query()
                ->where('dapb_customer_id', (int) $sponsor->c_userid)
                ->whereYear('dapb_awarded_at', now($tz)->year)
                ->whereMonth('dapb_awarded_at', now($tz)->month)
                ->pluck('dapb_milestone_no')
                ->map(fn ($value) => (int) $value)
                ->all();

            for ($milestoneNo = 1; $milestoneNo <= $qualifiedMilestones; $milestoneNo++) {
                if (in_array($milestoneNo, $alreadyAwardedMilestones, true)) {
                    continue;
                }

                $award = DirectAffiliatePerformanceBonusAward::create([
                    'dapb_customer_id' => (int) $sponsor->c_userid,
                    'dapb_milestone_no' => $milestoneNo,
                    'dapb_threshold_pv' => $thresholdPv,
                    'dapb_bonus_amount' => $bonusAmount,
                    'dapb_direct_referrals_count' => $directCount,
                    'dapb_direct_total_pv' => $directTotalPv,
                    'dapb_reference_order_id' => $referenceOrder?->ch_id ? (int) $referenceOrder->ch_id : null,
                    'dapb_awarded_by' => $awardedBy,
                    'dapb_awarded_at' => now(),
                    'dapb_notes' => 'Direct affiliate performance bonus awarded from level 1 direct referral PV milestone.',
                ]);

                $alreadyCredited = CustomerWalletLedger::query()
                    ->where('wl_wallet_type', 'cash')
                    ->where('wl_entry_type', 'credit')
                    ->where('wl_source_type', 'direct_affiliate_performance_bonus')
                    ->where('wl_source_id', (int) $award->dapb_id)
                    ->exists();

                if (!$alreadyCredited) {
                    $sponsor->c_totalincome = (float) ($sponsor->c_totalincome ?? 0) + $bonusAmount;
                    $sponsor->save();

                    CustomerWalletLedger::create([
                        'wl_customer_id' => (int) $sponsor->c_userid,
                        'wl_wallet_type' => 'cash',
                        'wl_entry_type' => 'credit',
                        'wl_amount' => $bonusAmount,
                        'wl_source_type' => 'direct_affiliate_performance_bonus',
                        'wl_source_id' => (int) $award->dapb_id,
                        'wl_reference_no' => $referenceOrder?->ch_checkout_id ? (string) $referenceOrder->ch_checkout_id : 'DAPB-' . $milestoneNo,
                        'wl_notes' => sprintf(
                            'Direct affiliate performance bonus milestone %d released at %.2f level-1 PV.',
                            $milestoneNo,
                            $directTotalPv
                        ),
                        'wl_created_by' => $awardedBy,
                    ]);

                    CustomerBonusNotification::notify(
                        $sponsor,
                        'direct_affiliate_performance_bonus',
                        'Affiliate performance bonus unlocked',
                        sprintf(
                            'You received PHP %s after your direct referrals reached %.2f PV.',
                            number_format($bonusAmount, 2),
                            $directTotalPv
                        ),
                        'direct_affiliate_performance_bonus',
                        (int) $award->dapb_id,
                        [
                            'milestone_no' => $milestoneNo,
                            'threshold_pv' => $thresholdPv,
                            'direct_referrals_count' => $directCount,
                            'direct_total_pv' => $directTotalPv,
                            'bonus_amount' => $bonusAmount,
                            'checkout_id' => (string) ($referenceOrder?->ch_checkout_id ?? ''),
                            'reference_order_id' => $referenceOrder?->ch_id ? (int) $referenceOrder->ch_id : null,
                        ]
                    );
                }
            }
        });
    }
}
