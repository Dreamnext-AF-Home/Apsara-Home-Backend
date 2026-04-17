<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberActivityLog extends Model
{
    protected $table = 'tbl_member_activity_logs';
    protected $primaryKey = 'mal_id';

    public $timestamps = false;

    protected $fillable = [
        'mal_customer_id',
        'mal_activity_type',
        'mal_action',
        'mal_description',
        'mal_resource_type',
        'mal_resource_id',
        'mal_details',
        'mal_metadata',
        'mal_ip_address',
        'mal_user_agent',
        'mal_created_at',
    ];

    protected $casts = [
        'mal_details' => 'array',
        'mal_metadata' => 'array',
        'mal_created_at' => 'datetime',
    ];

    /**
     * Get the customer associated with this activity log
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'mal_customer_id', 'c_userid');
    }

    /**
     * Activity types
     */
    public const ACTIVITY_LOGIN = 'login';
    public const ACTIVITY_LOGOUT = 'logout';
    public const ACTIVITY_PURCHASE = 'purchase';
    public const ACTIVITY_PROFILE_UPDATE = 'profile_update';
    public const ACTIVITY_WALLET_TRANSACTION = 'wallet_transaction';
    public const ACTIVITY_ENCASHMENT_REQUEST = 'encashment_request';
    public const ACTIVITY_VERIFICATION_REQUEST = 'verification_request';
    public const ACTIVITY_PASSWORD_CHANGE = 'password_change';
    public const ACTIVITY_USERNAME_CHANGE = 'username_change';
    public const ACTIVITY_ADDRESS_UPDATE = 'address_update';
    public const ACTIVITY_PAYOUT_METHOD_ADD = 'payout_method_add';
    public const ACTIVITY_PAYOUT_METHOD_DELETE = 'payout_method_delete';
    public const ACTIVITY_WISHLIST_UPDATE = 'wishlist_update';
    public const ACTIVITY_AFFILIATE_VOUCHER = 'affiliate_voucher';
    public const ACTIVITY_ACCOUNT_STATUS_CHANGE = 'account_status_change';

    /**
     * Action types
     */
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';
    public const ACTION_APPROVE = 'approve';
    public const ACTION_REJECT = 'reject';
    public const ACTION_VIEW = 'view';
    public const ACTION_SUBMIT = 'submit';
    public const ACTION_CANCEL = 'cancel';

    /**
     * Log a member activity
     */
    public static function log(
        int $customerId,
        string $activityType,
        string $action,
        ?string $description = null,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?array $details = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): self {
        return self::create([
            'mal_customer_id' => $customerId,
            'mal_activity_type' => $activityType,
            'mal_action' => $action,
            'mal_description' => $description,
            'mal_resource_type' => $resourceType,
            'mal_resource_id' => $resourceId,
            'mal_details' => $details,
            'mal_ip_address' => $ipAddress,
            'mal_user_agent' => $userAgent,
            'mal_created_at' => now(),
        ]);
    }

    /**
     * Get activities for a customer
     */
    public static function forCustomer(int $customerId)
    {
        return self::where('mal_customer_id', $customerId)
            ->orderByDesc('mal_created_at');
    }

    /**
     * Get activities by type
     */
    public static function byActivityType(string $activityType)
    {
        return self::where('mal_activity_type', $activityType)
            ->orderByDesc('mal_created_at');
    }

    /**
     * Get activities for a resource
     */
    public static function forResource(string $resourceType, int $resourceId)
    {
        return self::where('mal_resource_type', $resourceType)
            ->where('mal_resource_id', $resourceId)
            ->orderByDesc('mal_created_at');
    }

    /**
     * Get recent activities for a customer
     */
    public static function recentForCustomer(int $customerId, int $limit = 50)
    {
        return self::forCustomer($customerId)
            ->limit($limit)
            ->get();
    }

    /**
     * Get login activities for a customer
     */
    public static function loginsForCustomer(int $customerId, int $limit = 20)
    {
        return self::forCustomer($customerId)
            ->where('mal_activity_type', self::ACTIVITY_LOGIN)
            ->limit($limit)
            ->get();
    }

    /**
     * Get purchase activities for a customer
     */
    public static function purchasesForCustomer(int $customerId)
    {
        return self::forCustomer($customerId)
            ->where('mal_activity_type', self::ACTIVITY_PURCHASE)
            ->get();
    }

    /**
     * Get wallet transaction activities for a customer
     */
    public static function walletActivitiesForCustomer(int $customerId, int $limit = 50)
    {
        return self::forCustomer($customerId)
            ->where('mal_activity_type', self::ACTIVITY_WALLET_TRANSACTION)
            ->limit($limit)
            ->get();
    }

    /**
     * Get encashment activities for a customer
     */
    public static function encashmentActivitiesForCustomer(int $customerId)
    {
        return self::forCustomer($customerId)
            ->where('mal_activity_type', self::ACTIVITY_ENCASHMENT_REQUEST)
            ->get();
    }
}
