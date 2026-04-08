<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductReview extends Model
{
    protected $table = 'tbl_product_reviews';
    protected $primaryKey = 'pr_id';

    protected $fillable = [
        'pr_product_id',
        'pr_customer_id',
        'pr_order_id',
        'pr_rating',
        'pr_review',
    ];
}
