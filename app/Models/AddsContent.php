<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AddsContent extends Model
{
    protected $table = 'tbl_adds_content';
    protected $primaryKey = 'ac_id';

    protected $fillable = [
        'ac_image_path',
        'ac_video_path',
        'ac_date_created',
        'ac_status',
        'ac_page',
    ];

    protected $casts = [
        'ac_date_created' => 'date',
    ];
}
