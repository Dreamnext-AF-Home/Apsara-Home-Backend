<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class SupplierUser extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'tbl_supplier_user';
    protected $primaryKey = 'su_id';
    protected $keyType = 'int';
    public $incrementing = true;
    public $timestamps = false;

    protected $fillable = [
        'su_id',
        'su_level_type',
        'su_supplier',
        'su_fullname',
        'su_username',
        'su_password',
        'su_email',
        'su_date_created',
        'su_pin',
        'su_asession_stat',
        'su_last_logindate',
        'su_last_ipadd',
        'su_last_loginloc',
    ];

    protected $hidden = ['su_password'];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'su_supplier', 's_id');
    }
}
