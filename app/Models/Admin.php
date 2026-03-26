<?php 
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'tbl_admin';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'user_level_id',
        'supplier_id',
        'admin_permissions',
        'username',
        'passworde',
        'user_email',
        'fname',
        'avatar_url',
        'is_banned',
    ];

    protected $hidden = ['passworde'];

    protected $casts = [
        'admin_permissions' => 'array',
        'is_banned' => 'boolean',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 's_id');
    }
}
