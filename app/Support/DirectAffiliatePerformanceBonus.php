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

            $directReferrals = Customer::query()
                ->where('c_sponsor', (int) $sponsor->c_userid)
                ->get(['c_userid', 'c_gpv']);

            $directCount = $directReferrals->count();
            $directTotalPv = (float) $directReferrals->sum(fn (Customer $row) => (float) ($row->c_gpv ?? 0));
            $thresholdPv = self::thresholdPv();
            $bonusAmount = self::bonusAmount();
            $qualifiedMilestones = (int) floor($directTotalPv / $thresholdPv);

            if ($qualifiedMilestones <= 0 || $bonusAmount <= 0) {
                return;
            }

            $alreadyAwardedMilestones = DirectAffiliatePerformanceBonusAward::query()
                ->where('dapb_customer_id', (int) $sponsor->c_userid)
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
                }
            }
        });
    }
}
