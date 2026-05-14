<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Follower extends Model
{
    protected $table = 'tbl_followers';
    protected $primaryKey = 'follower_id';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'brand_id',
        'is_active',
        'followed_at',
        'updated_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'brand_id' => 'integer',
        'is_active' => 'boolean',
        'followed_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
