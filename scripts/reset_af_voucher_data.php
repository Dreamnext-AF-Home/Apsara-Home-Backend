<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::transaction(function (): void {
    $tablesToTruncate = [
        'tbl_customer_wallet_ledger',
        'tbl_referral_earnings',
        'tbl_bonuses',
        'tbl_group_purchase_bonus_awards',
        'tbl_direct_affiliate_performance_bonus_awards',
        'tbl_yearly_global_purchase_bonus_awards',
        'tbl_affiliate_voucher_issuances',
    ];

    foreach ($tablesToTruncate as $table) {
        if (Schema::hasTable($table)) {
            DB::statement(sprintf('TRUNCATE TABLE %s RESTART IDENTITY CASCADE', $table));
        }
    }

    $customerReset = [];
    foreach (['c_gpv', 'c_gpv_cmonth', 'c_wp', 'c_ap', 'c_sponsor'] as $column) {
        if (Schema::hasColumn('tbl_customer', $column)) {
            $customerReset[$column] = $column === 'c_sponsor' ? null : 0;
        }
    }

    if ($customerReset !== []) {
        DB::table('tbl_customer')->update($customerReset);
    }
});

echo "AF voucher, referral, bonus, and sponsor data reset completed.\n";
