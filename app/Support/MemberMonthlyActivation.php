<?php

namespace App\Support;

use App\Models\CheckoutHistory;
use App\Models\Customer;
use Carbon\Carbon;

class MemberMonthlyActivation
{
    public static function summary(Customer $customer): array
    {
        $timezone = 'Asia/Manila';
        $now = now($timezone);
        $threshold = max(0, (float) env('MEMBER_ACTIVATION_PV_THRESHOLD', 100));
        $deadlineDay = max(1, (int) env('MEMBER_ACTIVATION_DEADLINE_DAY', 7));

        $cycleStart = $now->copy()->startOfMonth();
        $cycleEnd = $now->copy()->endOfMonth();
        $effectiveDeadlineDay = min($deadlineDay, (int) $cycleEnd->day);
        $deadlineAt = $cycleStart->copy()->day($effectiveDeadlineDay)->endOfDay();

        $baseQuery = CheckoutHistory::query()
            ->where('ch_customer_id', (int) $customer->c_userid)
            ->where('ch_earned_pv', '>', 0)
            ->whereNotNull('ch_pv_posted_at')
            ->whereNotIn('ch_status', ['failed', 'cancelled', 'expired']);

        $currentMonthPv = (float) (clone $baseQuery)
            ->whereBetween('ch_pv_posted_at', [$cycleStart->toDateTimeString(), $cycleEnd->toDateTimeString()])
            ->sum('ch_earned_pv');

        $qualifyingPv = (float) (clone $baseQuery)
            ->whereBetween('ch_pv_posted_at', [$cycleStart->toDateTimeString(), $deadlineAt->toDateTimeString()])
            ->sum('ch_earned_pv');

        $isActive = $qualifyingPv >= $threshold;

        return [
            'status' => $isActive ? 'active' : 'inactive',
            'threshold_pv' => round($threshold, 2),
            'current_month_pv' => round($currentMonthPv, 2),
            'qualifying_pv' => round($qualifyingPv, 2),
            'remaining_pv' => round(max(0, $threshold - $qualifyingPv), 2),
            'deadline_day' => $effectiveDeadlineDay,
            'deadline_at' => $deadlineAt->toIso8601String(),
            'window_open' => $now->lessThanOrEqualTo($deadlineAt),
            'evaluated_at' => $now->toIso8601String(),
            'month_key' => $cycleStart->format('Y-m'),
            'month_label' => $cycleStart->format('F Y'),
        ];
    }
}
