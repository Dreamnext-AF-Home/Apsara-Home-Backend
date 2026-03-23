<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductBrand extends Model
{
    protected $table = 'tbl_product_brand';
    protected $primaryKey = 'pb_id';
    public $timestamps = false;

    protected $fillable = [
        'pb_name',
        'pb_image',
        'pb_status',
    ];

    protected $casts = [
        'pb_status' => 'integer',
    ];
}
