<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MemberActivityLog;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MemberActivityLogController extends Controller
{
    /**
     * Get activity logs for authenticated customer
     */
    public function myLogs(Request $request): JsonResponse
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customers can access this resource.'], 403);
        }

        $perPage = (int) $request->query('per_page', 50);
        $activityType = $request->query('activity_type');
        $action = $request->query('action');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $query = MemberActivityLog::where('mal_customer_id', $customer->c_userid);

        if ($activityType) {
            $query->where('mal_activity_type', $activityType);
        }

        if ($action) {
            $query->where('mal_action', $action);
        }

        if ($startDate) {
            $query->whereDate('mal_created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('mal_created_at', '<=', $endDate);
        }

        $logs = $query->orderByDesc('mal_created_at')->paginate($perPage);

        return response()->json([
            'data' => $logs->map(fn (MemberActivityLog $log) => $this->formatLog($log))->values(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Get all activity logs across all members (admin only)
     */
    public function allLogs(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 50);
        $customerId = $request->query('customer_id');
        $activityType = $request->query('activity_type');
        $action = $request->query('action');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');
        $search = trim((string) $request->query('q', ''));

        $query = MemberActivityLog::query()->with('customer');

        if ($customerId) {
            $query->where('mal_customer_id', $customerId);
        }

        if ($activityType) {
            $query->where('mal_activity_type', $activityType);
        }

        if ($action) {
            $query->where('mal_action', $action);
        }

        if ($startDate) {
            $query->whereDate('mal_created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('mal_created_at', '<=', $endDate);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('mal_description', 'ilike', "%{$search}%")
                    ->orWhere('mal_activity_type', 'ilike', "%{$search}%")
                    ->orWhere('mal_action', 'ilike', "%{$search}%")
                    ->orWhere('mal_ip_address', 'ilike', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery
                            ->where('c_email', 'ilike', "%{$search}%")
                            ->orWhere('c_username', 'ilike', "%{$search}%")
                            ->orWhere('c_fname', 'ilike', "%{$search}%")
                            ->orWhere('c_lname', 'ilike', "%{$search}%");
                    });
            });
        }

        $logs = $query->orderByDesc('mal_created_at')->paginate($perPage);

        return response()->json([
            'data' => $logs->map(fn (MemberActivityLog $log) => $this->formatLogWithCustomer($log))->values(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Get activity logs for a specific member (admin only)
     */
    public function memberLogs(Request $request, int $memberId): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 50);
        $activityType = $request->query('activity_type');
        $action = $request->query('action');
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        $query = MemberActivityLog::where('mal_customer_id', $memberId);

        if ($activityType) {
            $query->where('mal_activity_type', $activityType);
        }

        if ($action) {
            $query->where('mal_action', $action);
        }

        if ($startDate) {
            $query->whereDate('mal_created_at', '>=', $startDate);
        }

        if ($endDate) {
            $query->whereDate('mal_created_at', '<=', $endDate);
        }

        $logs = $query->orderByDesc('mal_created_at')->paginate($perPage);

        return response()->json([
            'data' => $logs->map(fn (MemberActivityLog $log) => $this->formatLog($log))->values(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Get activity log details
     */
    public function show(Request $request, int $logId): JsonResponse
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customers can access this resource.'], 403);
        }

        $log = MemberActivityLog::find($logId);

        if (!$log) {
            return response()->json(['message' => 'Activity log not found.'], 404);
        }

        if ($log->mal_customer_id !== $customer->c_userid) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'data' => $this->formatLog($log),
        ]);
    }

    /**
     * Get login history for customer
     */
    public function loginHistory(Request $request): JsonResponse
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customers can access this resource.'], 403);
        }

        $perPage = (int) $request->query('per_page', 20);
        $limit = (int) $request->query('limit', $perPage * 3);

        $logs = MemberActivityLog::forCustomer($customer->c_userid)
            ->where('mal_activity_type', MemberActivityLog::ACTIVITY_LOGIN)
            ->orderByDesc('mal_created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $logs->map(fn (MemberActivityLog $log) => $this->formatLog($log))->values(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Get purchase history for customer
     */
    public function purchaseHistory(Request $request): JsonResponse
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customers can access this resource.'], 403);
        }

        $perPage = (int) $request->query('per_page', 50);

        $logs = MemberActivityLog::forCustomer($customer->c_userid)
            ->where('mal_activity_type', MemberActivityLog::ACTIVITY_PURCHASE)
            ->orderByDesc('mal_created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => $logs->map(fn (MemberActivityLog $log) => $this->formatLog($log))->values(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Get wallet transaction history for customer
     */
    public function walletHistory(Request $request): JsonResponse
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customers can access this resource.'], 403);
        }

        $perPage = (int) $request->query('per_page', 50);

        $logs = MemberActivityLog::walletActivitiesForCustomer($customer->c_userid, $perPage * 3)
            ->paginate($perPage);

        return response()->json([
            'data' => collect($logs->items())->map(fn (MemberActivityLog $log) => $this->formatLog($log))->values(),
            'meta' => [
                'per_page' => $perPage,
                'total' => count($logs),
            ],
        ]);
    }

    /**
     * Manually create an activity log (customer for their own account)
     */
    public function createLog(Request $request): JsonResponse
    {
        $customer = $request->user();
        if (!$customer instanceof Customer) {
            return response()->json(['message' => 'Only customers can access this resource.'], 403);
        }

        $validated = $request->validate([
            'activity_type' => 'required|string|in:login,logout,purchase,profile_update,wallet_transaction,encashment_request,verification_request,password_change,username_change,address_update,payout_method_add,payout_method_delete,wishlist_update,affiliate_voucher,account_status_change',
            'action' => 'required|string|in:create,update,delete,approve,reject,view,submit,cancel',
            'description' => 'nullable|string|max:500',
            'resource_type' => 'nullable|string|max:100',
            'resource_id' => 'nullable|integer|min:1',
            'details' => 'nullable|array',
        ]);

        $log = MemberActivityLog::create([
            'mal_customer_id' => $customer->c_userid,
            'mal_activity_type' => (string) $validated['activity_type'],
            'mal_action' => (string) $validated['action'],
            'mal_description' => $validated['description'] ?? null,
            'mal_resource_type' => $validated['resource_type'] ?? null,
            'mal_resource_id' => $validated['resource_id'] ?? null,
            'mal_details' => $validated['details'] ?? null,
            'mal_ip_address' => $request->ip(),
            'mal_user_agent' => $request->userAgent(),
            'mal_created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Activity logged successfully.',
            'data' => $this->formatLog($log),
        ], 201);
    }


    /**
     * Format activity log for response
     */
    private function formatLog(MemberActivityLog $log): array
    {
        return [
            'id' => (int) $log->mal_id,
            'customer_id' => (int) $log->mal_customer_id,
            'activity_type' => (string) $log->mal_activity_type,
            'action' => (string) $log->mal_action,
            'description' => $log->mal_description,
            'resource_type' => $log->mal_resource_type,
            'resource_id' => $log->mal_resource_id ? (int) $log->mal_resource_id : null,
            'details' => $log->mal_details ?? [],
            'metadata' => $log->mal_metadata ?? [],
            'ip_address' => $log->mal_ip_address,
            'user_agent' => $log->mal_user_agent,
            'created_at' => $log->mal_created_at?->toDateTimeString(),
        ];
    }

    /**
     * Format activity log with customer info for response
     */
    private function formatLogWithCustomer(MemberActivityLog $log): array
    {
        $customer = $log->customer;
        $formatted = $this->formatLog($log);

        if ($customer) {
            $formatted['customer'] = [
                'id' => (int) $customer->c_userid,
                'username' => (string) $customer->c_username,
                'email' => (string) $customer->c_email,
                'name' => trim(implode(' ', array_filter([
                    $customer->c_fname,
                    $customer->c_mname,
                    $customer->c_lname,
                ]))),
                'phone' => (string) ($customer->c_mobile ?? ''),
                'avatar_url' => $customer->c_avatar_url,
                'account_status' => (int) ($customer->c_accnt_status ?? 0),
                'lock_status' => (int) ($customer->c_lockstatus ?? 0),
                'created_at' => $customer->created_at?->toDateTimeString(),
            ];
        }

        return $formatted;
    }
}
