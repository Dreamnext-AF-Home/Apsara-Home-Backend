<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerUsernameChangeRequest extends Model
{
    protected $table = 'tbl_customer_username_change_requests';
    protected $primaryKey = 'cuc_id';

    protected $fillable = [
        'cuc_customer_id',
        'cuc_reference_no',
        'cuc_current_username',
        'cuc_requested_username',
        'cuc_status',
        'cuc_review_notes',
        'cuc_reviewed_by',
        'cuc_reviewed_at',
    ];

    protected $casts = [
        'cuc_reviewed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'cuc_customer_id', 'c_userid');
    }
}
