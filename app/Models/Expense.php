<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $table = 'tbl_expenses';

    protected $fillable = [
        'e_title',
        'category_id',
        'amount',
        'intent',
        'transaction_date',
        'status',
        'created_by_admin_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'status' => 'integer',
        'category_id' => 'integer',
        'created_by_admin_id' => 'integer',
        'transaction_date' => 'date:Y-m-d',
    ];
}
