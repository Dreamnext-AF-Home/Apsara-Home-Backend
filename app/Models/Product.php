<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'tbl_product';
    protected $primaryKey = 'pd_id';
    public $timestamps = false;

    protected $fillable = [
        'pd_catid',
        'pd_catsubid',
        'pd_catsubid2',
        'pd_shopid',
        'pd_name',
        'pd_description',
        'pd_specifications',
        'pd_material',
        'pd_warranty',
        'pd_supplier',
        'pd_price_srp',
        'pd_price_dp',
        'pd_prodpv',
        'pd_qty',
        'pd_weight',
        'pd_psweight',
        'pd_pswidth',
        'pd_pslenght',
        'pd_psheight',
        'pd_assembly_required',
        'pd_preorder',
        'pd_preorder_value',
        'pd_parent_sku',
        'pd_type',
        'pd_shoptype',
        'pd_musthave',
        'pd_bestseller',
        'pd_user',
        'pd_usertype',
        'pd_date',
        'pd_last_update',
        'pd_status',
        'pd_image',
        'pd_salespromo',
    ];

    protected $casts = [
        'pd_price_srp'   => 'float',
        'pd_price_dp'    => 'float',
        'pd_prodpv'      => 'float',
        'pd_qty'         => 'float',
        'pd_weight'      => 'integer',
        'pd_psweight'           => 'float',
        'pd_pswidth'            => 'float',
        'pd_pslenght'           => 'float',
        'pd_psheight'           => 'float',
        'pd_assembly_required'  => 'integer',
        'pd_type'               => 'integer',
        'pd_musthave'    => 'integer',
        'pd_bestseller'  => 'integer',
        'pd_salespromo'  => 'integer',
        'pd_status'      => 'integer',
        'pd_date'        => 'datetime',
        'pd_last_update' => 'datetime',
    ];

    public function photos()
    {
        return $this->hasMany(ProductPhoto::class, 'pp_pdid', 'pd_id')
            ->orderBy('pp_id');
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class, 'pv_pdid', 'pd_id')
            ->orderBy('pv_id');
    }
}
