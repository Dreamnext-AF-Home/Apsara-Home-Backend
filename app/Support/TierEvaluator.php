<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\MemberTier;
use Illuminate\Support\Facades\DB;

class TierEvaluator
{
    public static function evaluate(Customer $customer): void
    {
        $storedRank = (int) ($customer->c_rank ?? 0);
        $currentRank = max(1, $storedRank);
        $qualifiedRank = self::resolveQualifiedRank($customer);

        if ($qualifiedRank > $currentRank) {
            $customer->c_rank = $qualifiedRank;
            $customer->save();
            self::storeUpgradeNotification($customer, $currentRank, $qualifiedRank);
            return;
        }

        if ($storedRank < 1) {
            $customer->c_rank = 1;
            $customer->save();
        }
    }

    private static function resolveQualifiedRank(Customer $customer): int
    {
        $tiers = MemberTier::query()
            ->where('mt_is_active', true)
            ->orderByDesc('mt_rank')
            ->get();
        $customerId = (int) $customer->c_userid;

        $personalPv       = self::getPersonalPv($customerId);
        $directCount      = self::getDirectReferralCount($customerId);
        $groupPv          = self::getGroupPv($customerId);

        foreach ($tiers as $tier) {
            $rank = (int) $tier->mt_rank;

            if ($personalPv < (float) ($tier->mt_min_pv ?? 0)) {
                continue;
            }

            if ($directCount < (int) ($tier->mt_min_direct_referrals ?? 0)) {
                continue;
            }

            if ($groupPv < (float) ($tier->mt_min_group_volume ?? 0)) {
                continue;
            }

            // Active Members check (directs with >= mt_min_active_member_pv cumulative PV)
            $minActiveMembers = (int) ($tier->mt_min_active_members ?? 0);
            if ($minActiveMembers > 0) {
                $pvThreshold = (float) ($tier->mt_min_active_member_pv ?? 300);
                if (self::countDirectsWithMinPv($customerId, $pvThreshold) < $minActiveMembers) {
                    continue;
                }
            }

            // Active Builders check (directs at Home Builder tier, rank >= 2)
            $minActiveBuilders = (int) ($tier->mt_min_active_builders ?? 0);
            if ($minActiveBuilders > 0) {
                if (self::countDirectsAtMinRank($customerId, 2) < $minActiveBuilders) {
                    continue;
                }
            }

            // Active Leaders check (directs at Home Stylist tier, rank >= 3)
            $minActiveLeaders = (int) ($tier->mt_min_active_leaders ?? 0);
            if ($minActiveLeaders > 0) {
                if (self::countDirectsAtMinRank($customerId, 3) < $minActiveLeaders) {
                    continue;
                }
            }

            return $rank;
        }

        return 1;
    }

    // Sum of PV from the customer's own purchases
    private static function getPersonalPv(int $customerId): float
    {
        return (float) DB::table('tbl_checkout_history')
            ->where('ch_customer_id', $customerId)
            ->whereNotNull('ch_pv_posted_at')
            ->sum('ch_earned_pv');
    }

    private static function getDirectReferralCount(int $customerId): int
    {
        return Customer::query()->where('c_sponsor', $customerId)->count();
    }

    // BFS walk of entire downline — sum personal PV of each member
    private static function getGroupPv(int $customerId): float
    {
        $visited = [];
        $queue   = [$customerId];
        $total   = 0.0;

        while (!empty($queue)) {
            $currentId = array_shift($queue);

            if (in_array($currentId, $visited, true)) {
                continue;
            }

            $visited[] = $currentId;

            $total += (float) DB::table('tbl_checkout_history')
                ->where('ch_customer_id', $currentId)
                ->whereNotNull('ch_pv_posted_at')
                ->sum('ch_earned_pv');

            $directs = Customer::query()
                ->where('c_sponsor', $currentId)
                ->pluck('c_userid')
                ->toArray();

            foreach ($directs as $directId) {
                if (!in_array($directId, $visited, true)) {
                    $queue[] = (int) $directId;
                }
            }
        }

        return $total;
    }

    // Count directs whose personal PV >= $minPv
    private static function countDirectsWithMinPv(int $customerId, float $minPv): int
    {
        $directs = Customer::query()
            ->where('c_sponsor', $customerId)
            ->pluck('c_userid');

        $count = 0;
        foreach ($directs as $directId) {
            $pv = (float) DB::table('tbl_checkout_history')
                ->where('ch_customer_id', $directId)
                ->whereNotNull('ch_pv_posted_at')
                ->sum('ch_earned_pv');

            if ($pv >= $minPv) {
                $count++;
            }
        }

        return $count;
    }

    // Count directs at a minimum tier rank
    private static function countDirectsAtMinRank(int $customerId, int $minRank): int
    {
        return Customer::query()
            ->where('c_sponsor', $customerId)
            ->where('c_rank', '>=', $minRank)
            ->count();
    }

    private static function storeUpgradeNotification(Customer $customer, int $previousRank, int $newRank): void
    {
        $newTier = MemberTier::getTierNameByRank($newRank);
        $previousTier = MemberTier::getTierNameByRank($previousRank);

        CustomerNotification::query()->firstOrCreate(
            [
                'cn_customer_id' => (int) $customer->c_userid,
                'cn_type' => 'tier_upgrade',
                'cn_source_type' => 'member_tier',
                'cn_source_id' => $newRank,
            ],
            [
                'cn_severity' => 'success',
                'cn_title' => 'Level Up Unlocked',
                'cn_message' => sprintf(
                    'Congratulations! You advanced from %s to %s. Your new badge is now active.',
                    $previousTier,
                    $newTier
                ),
                'cn_href' => '/profile/level-up?rank=' . $newRank,
                'cn_payload' => [
                    'previous_rank' => $previousRank,
                    'previous_tier' => $previousTier,
                    'new_rank' => $newRank,
                    'new_tier' => $newTier,
                ],
                'cn_created_at' => now(),
            ]
        );
    }
}
