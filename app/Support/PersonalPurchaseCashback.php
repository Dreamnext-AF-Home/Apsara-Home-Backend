<?php

namespace App\Support;

use App\Models\CheckoutHistory;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PersonalPurchaseCashback
{
    public static function rate(): float
    {
        return max(0, (float) env('PERSONAL_CASHBACK_RATE', 0.04));
    }

    public static function sourceBalance(Customer|int $customer): float
    {
        $customerId = $customer instanceof Customer
            ? (int) $customer->c_userid
            : (int) $customer;

        if ($customerId <= 0) {
            return 0.0;
        }

        return (float) CheckoutHistory::query()
            ->where('ch_customer_id', $customerId)
            ->where('ch_earned_pv', '>', 0)
            ->whereIn('ch_fulfillment_status', ['delivered', 'completed'])
            ->sum(DB::raw('ch_earned_pv * ' . self::rate()));
    }

    public static function reservedBalance(Customer|int $customer): float
    {
        $customerId = $customer instanceof Customer
            ? (int) $customer->c_userid
            : (int) $customer;

        if ($customerId <= 0 || !Schema::hasTable('tbl_affiliate_voucher_issuances')) {
            return 0.0;
        }

        return (float) DB::table('tbl_affiliate_voucher_issuances')
            ->where('avi_customer_id', $customerId)
            ->where('avi_status', 'active')
            ->selectRaw('SUM(avi_amount * COALESCE(avi_max_uses, 1)) as total_reserved')
            ->value('total_reserved') ?? 0;
    }

    public static function availableBalance(Customer|int $customer): float
    {
        return max(0, self::sourceBalance($customer) - self::reservedBalance($customer));
    }

    public static function defaultExpiryDays(): int
    {
        return max(1, (int) env('PERSONAL_CASHBACK_VOUCHER_EXPIRY_DAYS', 60));
    }
}
