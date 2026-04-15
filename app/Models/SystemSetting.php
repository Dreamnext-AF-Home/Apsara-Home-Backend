<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $table = 'tbl_system_settings';

    protected $fillable = [
        'system_name',
        'company_name',
        'support_email',
        'contact_number',
        'address',
        'branches',
        'logo_path',
        'favicon_path',
        'timezone',
        'currency',
        'date_format',
        'language',
    ];
}
