<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerWalletLedger;
use App\Models\EncashmentRequest;
use Illuminate\Support\Facades\Schema;

class CustomerCashWallet
{
    public static function balance(Customer|int $customer): float
    {
        $customerId = $customer instanceof Customer
            ? (int) $customer->c_userid
            : (int) $customer;

        if ($customerId <= 0) {
            return 0.0;
        }

        if (!Schema::hasTable('tbl_customer_wallet_ledger')) {
            if ($customer instanceof Customer) {
                return max(0, (float) ($customer->c_totalincome ?? 0));
            }

            $customer = Customer::query()->where('c_userid', $customerId)->first();

            return max(0, (float) ($customer?->c_totalincome ?? 0));
        }

        $credits = (float) CustomerWalletLedger::query()
            ->where('wl_customer_id', $customerId)
            ->where('wl_wallet_type', 'cash')
            ->where('wl_entry_type', 'credit')
            ->sum('wl_amount');

        $debits = (float) CustomerWalletLedger::query()
            ->where('wl_customer_id', $customerId)
            ->where('wl_wallet_type', 'cash')
            ->where('wl_entry_type', 'debit')
            ->sum('wl_amount');

        $ledgerBalance = max(0, $credits - $debits);
        if ($ledgerBalance > 0 || !$customer instanceof Customer) {
            return $ledgerBalance;
        }

        return max(0, (float) ($customer->c_totalincome ?? 0));
    }

    public static function lockedEncashmentAmount(int $customerId): float
    {
        if ($customerId <= 0) {
            return 0.0;
        }

        return (float) EncashmentRequest::query()
            ->where('er_customer_id', $customerId)
            ->whereIn('er_status', ['pending', 'approved_by_admin', 'on_hold'])
            ->sum('er_amount');
    }

    public static function availableForEncashment(Customer|int $customer): float
    {
        $customerId = $customer instanceof Customer
            ? (int) $customer->c_userid
            : (int) $customer;

        return max(0, self::balance($customer) - self::lockedEncashmentAmount($customerId));
    }
}
