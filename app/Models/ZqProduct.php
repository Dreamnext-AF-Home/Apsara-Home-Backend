<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZqProduct extends Model
{
    protected $table = 'tbl_zqproducts';
    protected $primaryKey = 'zqp_id';

    protected $fillable = [
        'zqp_external_id',
        'zqp_offer_id',
        'zqp_brand_type',
        'zqp_category_id',
        'zqp_category_name',
        'zqp_subject',
        'zqp_subject_cn',
        'zqp_description',
        'zqp_images',
        'zqp_primary_image',
        'zqp_specs',
        'zqp_source_type',
        'zqp_status',
        'zqp_import_status',
        'zqp_product_url',
        'zqp_target_currency',
        'zqp_shipping_to',
        'zqp_published_at',
        'zqp_source_created_at',
        'zqp_source_updated_at',
        'zqp_price_min_cents',
        'zqp_price_max_cents',
        'zqp_cost_min_cents',
        'zqp_cost_max_cents',
        'zqp_total_stock',
        'zqp_variant_count',
        'zqp_raw_payload',
    ];

    protected $casts = [
        'zqp_images' => 'array',
        'zqp_specs' => 'array',
        'zqp_raw_payload' => 'array',
        'zqp_published_at' => 'datetime',
        'zqp_source_created_at' => 'datetime',
        'zqp_source_updated_at' => 'datetime',
        'zqp_price_min_cents' => 'integer',
        'zqp_price_max_cents' => 'integer',
        'zqp_cost_min_cents' => 'integer',
        'zqp_cost_max_cents' => 'integer',
        'zqp_total_stock' => 'integer',
        'zqp_variant_count' => 'integer',
        'zqp_brand_type' => 'integer',
    ];
}
