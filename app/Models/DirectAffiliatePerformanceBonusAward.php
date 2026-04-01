<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DirectAffiliatePerformanceBonusAward extends Model
{
    protected $table = 'tbl_direct_affiliate_performance_bonus_awards';
    protected $primaryKey = 'dapb_id';

    protected $fillable = [
        'dapb_customer_id',
        'dapb_milestone_no',
        'dapb_threshold_pv',
        'dapb_bonus_amount',
        'dapb_direct_referrals_count',
        'dapb_direct_total_pv',
        'dapb_reference_order_id',
        'dapb_awarded_by',
        'dapb_awarded_at',
        'dapb_notes',
    ];

    protected $casts = [
        'dapb_threshold_pv' => 'float',
        'dapb_bonus_amount' => 'float',
        'dapb_direct_referrals_count' => 'int',
        'dapb_direct_total_pv' => 'float',
        'dapb_reference_order_id' => 'int',
        'dapb_awarded_by' => 'int',
        'dapb_awarded_at' => 'datetime',
    ];
}
