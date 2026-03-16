<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    protected $table = 'tbl_product_variant';
    protected $primaryKey = 'pv_id';
    public $timestamps = false;

    protected $fillable = [
        'pv_pdid',
        'pv_sku',
        'pv_name',
        'pv_color',
        'pv_color_hex',
        'pv_size',
        'pv_price_srp',
        'pv_price_dp',
        'pv_price_member',
        'pv_qty',
        'pv_status',
        'pv_date',
    ];

    protected $casts = [
        'pv_price_srp' => 'float',
        'pv_price_dp'  => 'float',
        'pv_price_member' => 'float',
        'pv_qty'       => 'float',
        'pv_status'    => 'integer',
        'pv_date'      => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'pv_pdid', 'pd_id');
    }

    public function photos()
    {
        return $this->hasMany(ProductVariantPhoto::class, 'pvp_pvid', 'pv_id')
            ->orderBy('pvp_sort')
            ->orderBy('pvp_id');
    }
}
