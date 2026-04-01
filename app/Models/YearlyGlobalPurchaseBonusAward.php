<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class YearlyGlobalPurchaseBonusAward extends Model
{
    protected $table = 'tbl_yearly_global_purchase_bonus_awards';
    protected $primaryKey = 'ygpba_id';

    protected $fillable = [
        'ygpba_customer_id',
        'ygpba_bonus_year',
        'ygpba_rank_no',
        'ygpba_yearly_pv',
        'ygpba_bonus_rate',
        'ygpba_bonus_amount',
        'ygpba_awarded_by',
        'ygpba_awarded_at',
        'ygpba_notes',
    ];

    protected $casts = [
        'ygpba_bonus_year' => 'int',
        'ygpba_rank_no' => 'int',
        'ygpba_yearly_pv' => 'float',
        'ygpba_bonus_rate' => 'float',
        'ygpba_bonus_amount' => 'float',
        'ygpba_awarded_by' => 'int',
        'ygpba_awarded_at' => 'datetime',
    ];
}
