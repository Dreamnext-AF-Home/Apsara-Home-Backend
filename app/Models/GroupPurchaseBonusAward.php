<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupPurchaseBonusAward extends Model
{
    protected $table = 'tbl_group_purchase_bonus_awards';
    protected $primaryKey = 'gpba_id';

    protected $fillable = [
        'gpba_customer_id',
        'gpba_source_customer_id',
        'gpba_level_no',
        'gpba_reference_order_id',
        'gpba_checkout_id',
        'gpba_earned_pv',
        'gpba_bonus_rate',
        'gpba_bonus_amount',
        'gpba_unlocked_max_level',
        'gpba_awarded_by',
        'gpba_awarded_at',
        'gpba_notes',
    ];

    protected $casts = [
        'gpba_source_customer_id' => 'int',
        'gpba_level_no' => 'int',
        'gpba_reference_order_id' => 'int',
        'gpba_earned_pv' => 'float',
        'gpba_bonus_rate' => 'float',
        'gpba_bonus_amount' => 'float',
        'gpba_unlocked_max_level' => 'int',
        'gpba_awarded_by' => 'int',
        'gpba_awarded_at' => 'datetime',
    ];
}
