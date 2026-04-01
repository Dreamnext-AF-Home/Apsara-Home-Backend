<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductActivityLog extends Model
{
    protected $table = 'tbl_product_activity_logs';
    protected $primaryKey = 'pal_id';
    public $timestamps = false;

    protected $fillable = [
        'pal_product_id',
        'pal_supplier_id',
        'pal_admin_id',
        'pal_supplier_user_id',
        'pal_action',
        'pal_status',
        'pal_product_name',
        'pal_product_sku',
        'pal_actor_name',
        'pal_actor_email',
        'pal_actor_role',
        'pal_changes',
        'pal_created_at',
    ];

    protected $casts = [
        'pal_product_id' => 'integer',
        'pal_supplier_id' => 'integer',
        'pal_admin_id' => 'integer',
        'pal_supplier_user_id' => 'integer',
        'pal_changes' => 'array',
        'pal_created_at' => 'datetime',
    ];
}
