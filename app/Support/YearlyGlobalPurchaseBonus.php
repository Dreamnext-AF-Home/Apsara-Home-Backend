<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerWalletLedger;
use App\Models\YearlyGlobalPurchaseBonusAward;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class YearlyGlobalPurchaseBonus
{
    public static function rate(): float
    {
        return max(0, (float) env('YEARLY_GLOBAL_BONUS_RATE', 0.01));
    }

    public static function topCount(): int
    {
        return max(1, (int) env('YEARLY_GLOBAL_BONUS_TOP_COUNT', 10));
    }

    public static function awardForYear(int $year, ?int $awardedBy = null): array
    {
        if (!Schema::hasTable('tbl_yearly_global_purchase_bonus_awards')) {
            return ['year' => $year, 'awards_created' => 0, 'awards' => []];
        }

        $rate = self::rate();
        $topCount = self::topCount();
        if ($rate <= 0 || $topCount <= 0) {
            return ['year' => $year, 'awards_created' => 0, 'awards' => []];
        }

        $yearlyPv = CustomerWalletLedger::query()
            ->selectRaw('wl_customer_id, SUM(wl_amount) as total_pv')
            ->where('wl_wallet_type', 'pv')
            ->where('wl_entry_type', 'credit')
            ->whereYear('created_at', $year)
            ->groupBy('wl_customer_id')
            ->orderByDesc('total_pv')
            ->orderBy('wl_customer_id')
            ->limit($topCount)
            ->get();

        $createdAwards = [];

        DB::transaction(function () use ($yearlyPv, $year, $rate, $awardedBy, &$createdAwards) {
            foreach ($yearlyPv->values() as $index => $row) {
                $customerId = (int) ($row->wl_customer_id ?? 0);
                $totalPv = (float) ($row->total_pv ?? 0);
                $rankNo = $index + 1;

                if ($customerId <= 0 || $totalPv <= 0) {
                    continue;
                }

                $existing = YearlyGlobalPurchaseBonusAward::query()
                    ->where('ygpba_bonus_year', $year)
                    ->where('ygpba_customer_id', $customerId)
                    ->first();

                if ($existing) {
                    $createdAwards[] = [
                        'customer_id' => $customerId,
                        'rank_no' => (int) $existing->ygpba_rank_no,
                        'yearly_pv' => (float) $existing->ygpba_yearly_pv,
                        'bonus_amount' => (float) $existing->ygpba_bonus_amount,
                        'status' => 'existing',
                    ];
                    continue;
                }

                $customer = Customer::query()->where('c_userid', $customerId)->lockForUpdate()->first();
                if (!$customer) {
                    continue;
                }

                $bonusAmount = round($totalPv * $rate, 2);
                if ($bonusAmount <= 0) {
                    continue;
                }

                $award = YearlyGlobalPurchaseBonusAward::create([
                    'ygpba_customer_id' => $customerId,
                    'ygpba_bonus_year' => $year,
                    'ygpba_rank_no' => $rankNo,
                    'ygpba_yearly_pv' => $totalPv,
                    'ygpba_bonus_rate' => $rate,
                    'ygpba_bonus_amount' => $bonusAmount,
                    'ygpba_awarded_by' => $awardedBy,
                    'ygpba_awarded_at' => now(),
                    'ygpba_notes' => sprintf('Yearly global purchase bonus for top %d affiliate ranking.', $rankNo),
                ]);

                $alreadyCredited = CustomerWalletLedger::query()
                    ->where('wl_wallet_type', 'cash')
                    ->where('wl_entry_type', 'credit')
                    ->where('wl_source_type', 'yearly_global_purchase_bonus')
                    ->where('wl_source_id', (int) $award->ygpba_id)
                    ->exists();

                if (!$alreadyCredited) {
                    $customer->c_totalincome = (float) ($customer->c_totalincome ?? 0) + $bonusAmount;
                    $customer->save();

                    CustomerWalletLedger::create([
                        'wl_customer_id' => $customerId,
                        'wl_wallet_type' => 'cash',
                        'wl_entry_type' => 'credit',
                        'wl_amount' => $bonusAmount,
                        'wl_source_type' => 'yearly_global_purchase_bonus',
                        'wl_source_id' => (int) $award->ygpba_id,
                        'wl_reference_no' => sprintf('YGPB-%d-%02d', $year, $rankNo),
                        'wl_notes' => sprintf('Yearly global purchase bonus credited for rank %d in %d.', $rankNo, $year),
                        'wl_created_by' => $awardedBy,
                    ]);
                }

                $createdAwards[] = [
                    'customer_id' => $customerId,
                    'rank_no' => $rankNo,
                    'yearly_pv' => $totalPv,
                    'bonus_amount' => $bonusAmount,
                    'status' => 'created',
                ];
            }
        });

        return [
            'year' => $year,
            'awards_created' => collect($createdAwards)->where('status', 'created')->count(),
            'awards' => $createdAwards,
        ];
    }
}
