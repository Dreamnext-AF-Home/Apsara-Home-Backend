<?php

namespace App\Support;

use App\Models\MemberActivityLog;
use Illuminate\Http\Request;

class MemberActivityLogger
{
    /**
     * Log a login activity
     */
    public static function logLogin(int $customerId, ?Request $request = null): void
    {
        self::log(
            customerId: $customerId,
            activityType: MemberActivityLog::ACTIVITY_LOGIN,
            action: MemberActivityLog::ACTION_CREATE,
            description: 'Member logged in',
            request: $request
        );
    }

    /**
     * Log a logout activity
     */
    public static function logLogout(int $customerId, ?Request $request = null): void
    {
        self::log(
            customerId: $customerId,
            activityType: MemberActivityLog::ACTIVITY_LOGOUT,
            action: MemberActivityLog::ACTION_CREATE,
            description: 'Member logged out',
            request: $request
        );
    }

    /**
     * Log a purchase
     */
    public static function logPurchase(int $customerId, int $orderId, float $amount, ?Request $request = null): void
    {
        self::log(
            customerId: $customerId,
            activityType: MemberActivityLog::ACTIVITY_PURCHASE,
            action: MemberActivityLog::ACTION_CREATE,
            description: "Purchased order #{$orderId}",
            resourceType: 'order',
            resourceId: $orderId,
            details: ['amount' => $amount],
            request: $request
        );
    }

    /**
     * Log a profile update
     */
    public static function logProfileUpdate(int $customerId, array $changes, ?Request $request = null): void
    {
        self::log(
            customerId: $customerId,
            activityType: MemberActivityLog::ACTIVITY_PROFILE_UPDATE,
            action: MemberActivityLog::ACTION_UPDATE,
            description: 'Member profile updated',
            details: $changes,
            request: $request
        );
    }

    /**
     * Log a wallet transaction
     */
    public static function logWalletTransaction(
        int $customerId,
        string $walletType,
        string $entryType,
        float $amount,
        string $source,
        ?int $sourceId = null,
        ?Request $request = null
    ): void {
        self::log(
            customerId: $customerId,
            activityType: MemberActivityLog::ACTIVITY_WALLET_TRANSACTION,
            action: MemberActivityLog::ACTION_CREATE,
            description: "{$entryType} {$amount} to {$walletType} wallet ({$source})",
            resourceType: 'wallet_ledger',
            resourceId: $sourceId,
            details: [
                'wallet_type' => $walletType,
                'entry_type' => $entryType,
                'amount' => $amount,
                'source' => $source,
            ],
            request: $request
        );
    }

    /**
     * Log an encashment request
     */
    public static function logEncashmentRequest(
        int $customerId,
        int $encashmentId,
        float $amount,
        string $channel,
        ?Request $request = null
    ): void {
        self::log(
            customerId: $customerId,
            activityType: MemberActivityLog::ACTIVITY_ENCASHMENT_REQUEST,
            action: MemberActivityLog::ACTION_SUBMIT,
            description: "Submitted encashment request for {$amount} via {$channel}",
            resourceType: 'encashment_request',
            resourceId: $encashmentId,
            details: [
                'amount' => $amount,
                'channel' => $channel,
            ],
            request: $request
        );
    }

    /**
     * Log an encashment approval
     */
    public static function logEncashmentApproved(int $customerId, int $encashmentId, ?Request $request = null): void
    {
        self::log(
            customerId: $customerId,
            activityType: MemberActivityLog::ACTIVITY_ENCASHMENT_REQUEST,
            action: MemberActivityLog::ACTION_APPROVE,
            description: 'Encashment request approved',
            resourceType: 'encashment_request',
            resourceId: $encashmentId,
            request: $request
        );
    }

    /**
     * Log an encashment rejection
     */
    public static function logEncashmentRejected(int $customerId, int $encashmentId, ?string $reason = null, ?Request $request = null): void
    {
        self::log(
            customerId: $customerId,
            activityType: MemberActivityLog::ACTIVITY_ENCASHMENT_REQUEST,
            action: MemberActivityLog::ACTION_REJECT,
            description: 'Encashment request rejected' . ($reason ? ": {$reason}" : ''),
            resourceType: 'encashment_request',
            resourceId: $encashmentId,
            details: $reason ? ['reason' => $reason] : null,
            request: $request
        );
    }

    /**
     * Log a verification request submission
     */
    public static function logVerificationRequest(int $customerId, int $verificationId, ?Request $request = null): void
    {
        self::log(
            customerId: $customerId,
            activityType: MemberActivityLog::ACTIVITY_VERIFICATION_REQUEST,
            action: MemberActivityLog::ACTION_SUBMIT,
            description: 'KYC verification request submitted',
            resourceType: 'verification_request',
            resourceId: $verificationId,
            request: $request
        );
    }

    /**
     * Log a password change
     */
    public static function logPasswordChange(int $customerId, ?Request $request = null): void
    {
        self::log(
            customerId: $customerId,
            activityType: MemberActivityLog::ACTIVITY_PASSWORD_CHANGE,
            action: MemberActivityLog::ACTION_UPDATE,
            description: 'Password changed',
            request: $request
        );
    }

    /**
     * Log a username change
     */
    public static function logUsernameChange(int $customerId, string $oldUsername, string $newUsername, ?Request $request = null): void
    {
        self::log(
            customerId: $customerId,
            activityType: MemberActivityLog::ACTIVITY_USERNAME_CHANGE,
            action: MemberActivityLog::ACTION_UPDATE,
            description: "Username changed from {$oldUsername} to {$newUsername}",
            details: [
                'old_username' => $oldUsername,
                'new_username' => $newUsername,
            ],
            request: $request
        );
    }

    /**
     * Log an address update
     */
    public static function logAddressUpdate(int $customerId, int $addressId, array $changes, ?Request $request = null): void
    {
        self::log(
            customerId: $customerId,
            activityType: MemberActivityLog::ACTIVITY_ADDRESS_UPDATE,
            action: MemberActivityLog::ACTION_UPDATE,
            description: 'Address information updated',
            resourceType: 'address',
            resourceId: $addressId,
            details: $changes,
            request: $request
        );
    }

    /**
     * Log payout method addition
     */
    public static function logPayoutMethodAdded(int $customerId, int $methodId, string $methodType, ?Request $request = null): void
    {
        self::log(
            customerId: $customerId,
            activityType: MemberActivityLog::ACTIVITY_PAYOUT_METHOD_ADD,
            action: MemberActivityLog::ACTION_CREATE,
            description: "Added {$methodType} payout method",
            resourceType: 'payout_method',
            resourceId: $methodId,
            details: ['method_type' => $methodType],
            request: $request
        );
    }

    /**
     * Log payout method deletion
     */
    public static function logPayoutMethodDeleted(int $customerId, int $methodId, ?Request $request = null): void
    {
        self::log(
            customerId: $customerId,
            activityType: MemberActivityLog::ACTIVITY_PAYOUT_METHOD_DELETE,
            action: MemberActivityLog::ACTION_DELETE,
            description: 'Payout method deleted',
            resourceType: 'payout_method',
            resourceId: $methodId,
            request: $request
        );
    }

    /**
     * Log affiliate voucher creation
     */
    public static function logAffiliateVoucher(int $customerId, int $voucherId, float $amount, ?Request $request = null): void
    {
        self::log(
            customerId: $customerId,
            activityType: MemberActivityLog::ACTIVITY_AFFILIATE_VOUCHER,
            action: MemberActivityLog::ACTION_CREATE,
            description: "Created affiliate voucher for {$amount}",
            resourceType: 'affiliate_voucher',
            resourceId: $voucherId,
            details: ['amount' => $amount],
            request: $request
        );
    }

    /**
     * Log an account status change
     */
    public static function logAccountStatusChange(int $customerId, int $oldStatus, int $newStatus, ?Request $request = null): void
    {
        $statusNames = [
            0 => 'Pending',
            1 => 'Active',
            2 => 'KYC Review',
        ];
        $oldStatusLabel = $statusNames[$oldStatus] ?? 'Unknown';
        $newStatusLabel = $statusNames[$newStatus] ?? 'Unknown';

        self::log(
            customerId: $customerId,
            activityType: MemberActivityLog::ACTIVITY_ACCOUNT_STATUS_CHANGE,
            action: MemberActivityLog::ACTION_UPDATE,
            description: "Account status changed from {$oldStatusLabel} to {$newStatusLabel}",
            details: [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ],
            request: $request
        );
    }

    /**
     * Generic log method
     */
    private static function log(
        int $customerId,
        string $activityType,
        string $action,
        ?string $description = null,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?array $details = null,
        ?Request $request = null
    ): void {
        $ipAddress = null;
        $userAgent = null;

        if ($request) {
            $ipAddress = $request->ip();
            $userAgent = $request->userAgent();
        }

        MemberActivityLog::log(
            customerId: $customerId,
            activityType: $activityType,
            action: $action,
            description: $description,
            resourceType: $resourceType,
            resourceId: $resourceId,
            details: $details,
            ipAddress: $ipAddress,
            userAgent: $userAgent
        );
    }
}
