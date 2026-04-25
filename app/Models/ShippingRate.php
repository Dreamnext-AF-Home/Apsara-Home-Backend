<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingRate extends Model
{
    protected $table = 'tbl_shipping_rates';
    protected $primaryKey = 'sr_id';

    protected $fillable = [
        'sr_province',
        'sr_city',
        'sr_province_key',
        'sr_city_key',
        'sr_fee',
        'sr_status',
    ];

    protected $casts = [
        'sr_fee' => 'float',
        'sr_status' => 'boolean',
    ];
}
