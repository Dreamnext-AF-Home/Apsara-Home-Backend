<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EncashmentPayoutMethod extends Model
{
    protected $table = 'tbl_encashment_payout_methods';
    protected $primaryKey = 'epm_id';

    protected $fillable = [
        'epm_customer_id',
        'epm_label',
        'epm_method_type',
        'epm_channel',
        'epm_account_name',
        'epm_account_number',
        'epm_mobile_number',
        'epm_email',
        'epm_bank_name',
        'epm_bank_code',
        'epm_account_type',
        'epm_card_holder_name',
        'epm_card_brand',
        'epm_card_last4',
        'epm_is_default',
    ];

    protected $casts = [
        'epm_is_default' => 'boolean',
    ];
}
