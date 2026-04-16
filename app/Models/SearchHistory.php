<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchHistory extends Model
{
    protected $table = 'tbl_search_history';
    protected $primaryKey = 'sh_id';
    public $timestamps = false;

    protected $fillable = [
        'sh_customer_id',
        'sh_query',
        'sh_results_count',
        'sh_date_created',
    ];

    protected $casts = [
        'sh_results_count' => 'integer',
        'sh_date_created' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'sh_customer_id', 'c_id');
    }
}
